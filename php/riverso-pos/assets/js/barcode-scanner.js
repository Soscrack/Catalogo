/**
 * Lector de códigos de barras con la cámara, compartido por Avisos, Cotizaciones y Facturación.
 *
 * Usa BarcodeDetector cuando el navegador lo trae; si no (Safari / iPhone), zxing-cpp en
 * WebAssembly servido desde assets/vendor/zxing-wasm.
 *
 *   RiversoBarcodeScanner.open({ onCode })           diálogo propio: leer un código y cerrarse
 *   RiversoBarcodeScanner.start({ video, onCode… })  solo la lectura, sobre un <video> de la pantalla
 */
(function () {
  "use strict";

  if (window.RiversoBarcodeScanner) return;

  // Solo códigos de producto: la etiqueta propia trae además un QR que no identifica nada.
  var NATIVE_FORMATS = ["ean_13", "ean_8", "upc_a", "upc_e", "code_128", "code_39", "itf"];
  var ZXING_FORMATS = ["EAN-13", "EAN-8", "UPC-A", "UPC-E", "Code128", "Code39", "ITF"];
  var READY = "Pon el código de barras dentro del marco.";

  var script = document.currentScript;
  var assetBase = script && script.src ? script.src.replace(/\/js\/barcode-scanner\.js.*$/, "") : "";
  var zxingUrl = assetBase + "/vendor/zxing-wasm/zxing-reader.iife.js";
  var zxingWasmUrl = assetBase + "/vendor/zxing-wasm/zxing_reader.wasm";

  var detector = null;
  var dialog = null;

  function supported() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
  }

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement("script");
      s.src = src;
      s.onload = resolve;
      s.onerror = function () {
        reject(new Error("No se pudo cargar el lector de códigos."));
      };
      document.head.appendChild(s);
    });
  }

  function nativeDetector() {
    if (!("BarcodeDetector" in window)) return Promise.resolve(null);
    return window.BarcodeDetector.getSupportedFormats()
      .then(function (available) {
        var formats = NATIVE_FORMATS.filter(function (f) {
          return available.indexOf(f) !== -1;
        });
        if (formats.indexOf("ean_13") === -1) return null;
        var native = new window.BarcodeDetector({ formats: formats });
        return {
          detect: function (video) {
            return native.detect(video).then(function (results) {
              return results.map(function (r) {
                return r.rawValue;
              });
            });
          },
        };
      })
      .catch(function () {
        return null;
      });
  }

  /** zxing-cpp en WebAssembly: iPhone y todo navegador sin BarcodeDetector. */
  function zxingDetector() {
    return loadScript(zxingUrl).then(function () {
      var zxing = window.ZXingWASM;
      zxing.prepareZXingModule({
        overrides: {
          locateFile: function (path, prefix) {
            return /\.wasm$/.test(path) ? zxingWasmUrl : prefix + path;
          },
        },
      });
      var canvas = document.createElement("canvas");
      var ctx = canvas.getContext("2d", { willReadFrequently: true });
      return {
        detect: function (video) {
          var vw = video.videoWidth;
          var vh = video.videoHeight;
          if (!vw || !vh) return Promise.resolve([]);
          // Solo la franja central, donde está el marco: menos píxeles por cuadro.
          var cw = Math.round(vw * 0.92);
          var ch = Math.round(vh * 0.6);
          var scale = Math.min(1, 1280 / cw);
          canvas.width = Math.round(cw * scale);
          canvas.height = Math.round(ch * scale);
          ctx.drawImage(video, (vw - cw) / 2, (vh - ch) / 2, cw, ch, 0, 0, canvas.width, canvas.height);
          var image = ctx.getImageData(0, 0, canvas.width, canvas.height);
          // Sin búsqueda exhaustiva ni rotación el lector pierde más de la mitad de las cajas.
          return zxing
            .readBarcodes(image, {
              formats: ZXING_FORMATS,
              tryHarder: true,
              tryRotate: true,
              tryInvert: false,
              tryDownscale: true,
              maxNumberOfSymbols: 4,
            })
            .then(function (results) {
              return results
                .filter(function (r) {
                  return r.isValid && r.text;
                })
                .map(function (r) {
                  return r.text;
                });
            });
        },
      };
    });
  }

  function getDetector() {
    if (!detector) {
      detector = nativeDetector().then(function (native) {
        return native || zxingDetector();
      });
      detector.catch(function () {
        detector = null;
      });
    }
    return detector;
  }

  function validEan(code) {
    if (!/^(\d{8}|\d{12}|\d{13})$/.test(code)) return false;
    var digits = code.split("").map(Number);
    var check = digits.pop();
    var sum = 0;
    digits.reverse().forEach(function (d, i) {
      sum += d * (i % 2 === 0 ? 3 : 1);
    });
    return (10 - (sum % 10)) % 10 === check;
  }

  function cameraError(err) {
    var name = err && err.name;
    if (name === "NotAllowedError" || name === "SecurityError") {
      return "La cámara está bloqueada para esta página. Actívala en el navegador o escribe el código.";
    }
    if (name === "NotFoundError" || name === "OverconstrainedError") return "No encontré una cámara. Escribe el código.";
    if (name === "NotReadableError") return "Otra aplicación está usando la cámara.";
    return (err && err.message) || "No se pudo abrir la cámara.";
  }

  /**
   * Abre la cámara sobre opts.video y lee hasta encontrar un código.
   *
   * opts.onCode(code)      se leyó un código; la cámara ya está apagada.
   * opts.onChoices(codes)  hay más de un código distinto a la vista; la lectura queda en pausa
   *                        hasta choose(code) o resume().
   * opts.onStatus(text)    texto para mostrar bajo el video.
   * opts.onError(message)  no se pudo usar la cámara; ya está apagada.
   *
   * @return {{stop: function, resume: function, choose: function}}
   */
  function start(opts) {
    var video = opts.video;
    var session = { stream: null, detector: null, running: false, stopped: false, last: "", hits: 0, multi: 0, timer: null };

    function status(text) {
      if (opts.onStatus) opts.onStatus(text);
    }

    function stop() {
      if (session.stopped) return;
      session.stopped = true;
      session.running = false;
      clearTimeout(session.timer);
      if (session.stream) {
        session.stream.getTracks().forEach(function (t) {
          t.stop();
        });
      }
      video.pause();
      video.srcObject = null;
    }

    function accept(code) {
      if (session.stopped) return;
      if (navigator.vibrate) navigator.vibrate(60);
      stop();
      opts.onCode(code);
    }

    function tick() {
      if (!session.running) return;
      session.detector
        .detect(video)
        .then(function (texts) {
          if (!session.running) return;
          var codes = [];
          texts.forEach(function (t) {
            t = String(t || "").trim();
            if (t && codes.indexOf(t) === -1) codes.push(t);
          });
          if (codes.length === 1) {
            session.multi = 0;
            session.hits = codes[0] === session.last ? session.hits + 1 : 1;
            session.last = codes[0];
            // Un EAN con dígito verificador correcto basta; el resto se confirma con dos lecturas iguales.
            if (session.hits >= 2 || validEan(codes[0])) {
              accept(codes[0]);
              return;
            }
          } else if (codes.length > 1) {
            session.multi += 1;
            // Dos cajas distintas en el encuadre: elige la persona, no el primero que leyó el lector.
            if (session.multi >= 2 && opts.onChoices) {
              session.running = false;
              status("Hay más de un código a la vista. ¿Cuál?");
              opts.onChoices(codes);
              return;
            }
          } else {
            session.multi = 0;
          }
          session.timer = setTimeout(tick, 120);
        })
        .catch(function () {
          session.timer = setTimeout(tick, 400);
        });
    }

    function resume() {
      if (session.stopped || session.running || !session.detector) return;
      session.multi = 0;
      session.hits = 0;
      session.running = true;
      status(READY);
      tick();
    }

    function fail(message) {
      stop();
      if (opts.onError) opts.onError(message);
    }

    if (!supported()) {
      setTimeout(function () {
        fail("Este navegador no deja usar la cámara. Escribe el código.");
      }, 0);
    } else {
      status("Abriendo cámara…");
      navigator.mediaDevices
        .getUserMedia({
          audio: false,
          video: { facingMode: { ideal: "environment" }, width: { ideal: 1920 }, height: { ideal: 1080 } },
        })
        .then(function (stream) {
          if (session.stopped) {
            stream.getTracks().forEach(function (t) {
              t.stop();
            });
            return;
          }
          // Se guarda antes de cargar el lector: si el lector falla, stop() apaga la cámara.
          session.stream = stream;
          video.srcObject = stream;
          return Promise.all([video.play(), getDetector()]).then(function (parts) {
            if (session.stopped) return;
            session.detector = parts[1];
            session.running = true;
            status(READY);
            tick();
          });
        })
        .catch(function (err) {
          if (!session.stopped) fail(cameraError(err));
        });
    }

    return { stop: stop, resume: resume, choose: accept };
  }

  /* ===================== Diálogo propio ===================== */

  function buildDialog() {
    var overlay = document.createElement("div");
    overlay.className = "rbs-overlay";
    overlay.hidden = true;
    overlay.innerHTML =
      '<div class="rbs-dialog" role="dialog" aria-modal="true" aria-label="Escanear código de barras">' +
      '<header class="rbs-head"><strong>Escanear código de barras</strong>' +
      '<button type="button" class="rbs-close" aria-label="Cerrar">×</button></header>' +
      '<div class="rbs-view"><video playsinline muted></video><div class="rbs-frame" aria-hidden="true"></div></div>' +
      '<p class="rbs-status" aria-live="polite"></p>' +
      '<div class="rbs-choices" hidden></div>' +
      "</div>";
    document.body.appendChild(overlay);

    var ui = {
      overlay: overlay,
      video: overlay.querySelector("video"),
      status: overlay.querySelector(".rbs-status"),
      choices: overlay.querySelector(".rbs-choices"),
      session: null,
    };

    overlay.addEventListener("click", function (e) {
      if (e.target === overlay || e.target.closest(".rbs-close")) {
        close();
        return;
      }
      var choice = e.target.closest("[data-code]");
      if (!choice || !ui.session) return;
      var code = choice.getAttribute("data-code");
      ui.choices.hidden = true;
      if (code) ui.session.choose(code);
      else ui.session.resume();
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !overlay.hidden) close();
    });
    document.addEventListener("visibilitychange", function () {
      if (document.hidden && !overlay.hidden) close();
    });
    return ui;
  }

  function close() {
    if (!dialog) return;
    if (dialog.session) dialog.session.stop();
    dialog.session = null;
    dialog.overlay.hidden = true;
    dialog.choices.hidden = true;
  }

  /**
   * Abre el diálogo, lee un código y lo entrega en opts.onCode(code).
   */
  function open(opts) {
    if (!dialog) dialog = buildDialog();
    close();
    var ui = dialog;
    ui.status.textContent = "";
    ui.overlay.hidden = false;
    ui.session = start({
      video: ui.video,
      onStatus: function (text) {
        ui.status.textContent = text || "";
      },
      onCode: function (code) {
        close();
        opts.onCode(code);
      },
      onChoices: function (codes) {
        ui.choices.innerHTML = "";
        codes.concat([""]).forEach(function (code) {
          var btn = document.createElement("button");
          btn.type = "button";
          btn.className = code ? "rbs-choice" : "rbs-choice rbs-choice-resume";
          btn.setAttribute("data-code", code);
          btn.textContent = code || "Seguir escaneando";
          ui.choices.appendChild(btn);
        });
        ui.choices.hidden = false;
      },
      // El diálogo queda abierto con el motivo, para que la persona lo lea y cierre.
      onError: function (message) {
        ui.session = null;
        ui.status.textContent = message;
      },
    });
  }

  window.RiversoBarcodeScanner = { supported: supported, start: start, open: open, close: close };
})();
