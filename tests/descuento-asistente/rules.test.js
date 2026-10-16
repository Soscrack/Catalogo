/**
 * Reglas del Asistente de Descuento (assets/js/discount-assistant.js), sin DOM.
 * Uso: node tests/descuento-asistente/rules.test.js
 */
const path = require("path").join(__dirname, "../../php/riverso-pos/assets/js/") + "/";
global.window = global;
require(path + "line-discounts.js");
require(path + "discount-assistant.js");
const DA = global.RiversoDiscountAssistant;
const assert = require("assert");
function comp(name, unit, actitud, extra) { return Object.assign({ competidor: name, precio_unitario: unit, actitud, cuenta: true }, extra || {}); }
const base = { gross: 110000, units: 2500, cost: 20, finalTotal: 110000 };
function run(label, input, check) {
  const r = DA.compute(Object.assign({}, base, input), input.settings || {});
  check(r);
  console.log("ok -", label, r.status, r.suggestion ? r.suggestion.value + " (" + r.suggestion.source + ", " + r.suggestion.markerKey + ")" : "");
}
run("ejemplo 1: competitivos sobre deseado", { competitors: [comp("Sande", 36, "competitivo"), comp("Dimafi", 40, "competitivo"), comp("Sodimac", 38, "codicioso")] }, r => {
  assert.equal(r.status, "ok"); assert.equal(r.suggestion.value, 89950);
  assert.equal(r.floor, 80000); assert.equal(Math.round(r.ceilMin*100)/100, 97777.78); assert.equal(r.desired, 85000);
  assert.ok(r.volume.ok);
});
run("ejemplo 2: codicioso barato manda", { competitors: [comp("Sande", 36, "competitivo"), comp("Dimafi", 40, "competitivo"), comp("Sodimac", 33, "codicioso")] }, r => {
  assert.equal(r.suggestion.value, 82450); assert.equal(r.suggestion.source, "greedy"); assert.equal(r.suggestion.markerKey, "greedy");
});
run("deseado entre competitivos", { competitors: [comp("A", 30, "competitivo"), comp("B", 40, "competitivo")] }, r => {
  assert.equal(r.suggestion.value, 85000); assert.equal(r.suggestion.markerKey, "desired");
});
run("todos competitivos bajo deseado: promedio", { competitors: [comp("A", 32.5, "competitivo"), comp("B", 33.3, "competitivo")] }, r => {
  // 81250 y 83250 → promedio 82250
  assert.equal(r.suggestion.value, 82250); assert.equal(r.suggestion.markerKey, "suggested");
});
run("promedio bajo piso: piso gana", { competitors: [comp("A", 28, "competitivo"), comp("B", 30, "competitivo")] }, r => {
  assert.equal(r.suggestion.value, 80000); assert.equal(r.suggestion.markerKey, "max");
});
run("codicioso bajo piso: piso gana con aviso", { competitors: [comp("S", 30, "codicioso")] }, r => {
  assert.equal(r.suggestion.value, 80000); assert.ok(r.warnings.length === 1);
});
run("solo codicioso, deseado bajo él", { competitors: [comp("S", 40, "codicioso")] }, r => {
  assert.equal(r.suggestion.value, 85000);
});
run("sin clasificar → falta información", { competitors: [comp("X", 36, "sin_clasificar")] }, r => {
  assert.equal(r.status, "no_info"); assert.equal(r.reference.length, 1);
});
run("no cuenta (similar/desactualizado)", { competitors: [comp("X", 36, "competitivo", { cuenta: false })] }, r => {
  assert.equal(r.status, "no_info");
});
run("factor 1,8 → sin descuento", { gross: 90000 }, r => { assert.equal(r.status, "no_discount"); });
run("factor 1,85: deseado sobre el mínimo → mínimo", { gross: 92500, competitors: [comp("A", 40, "competitivo"), comp("B", 30, "competitivo")] }, r => {
  // ceilMin = 82222.22 ; deseado 85000 entre 75000 y 100000 → limitar a 82222 → 82200
  assert.equal(r.suggestion.value, 82200); assert.equal(r.suggestion.markerKey, "min");
});
run("sin costo", { cost: null }, r => { assert.equal(r.status, "no_cost"); });
run("volumen: 1.500 u. y $66.000 no cumple", { units: 1500, gross: 66000 }, r => { assert.ok(!r.volume.ok); });
run("volumen: 1.500 u. y $80.000 cumple por unidades", { units: 1500, gross: 80000, cost: 20 }, r => { assert.ok(r.volume.ok && r.volume.byUnits); });
run("volumen: 1.000 u. justas no cumple por unidades", { units: 1000, gross: 80000, cost: 40 }, r => { assert.ok(!r.volume.ok); });
run("volumen: 200 u. y $130.000 cumple por monto", { units: 200, gross: 130000, cost: 300 }, r => { assert.ok(r.volume.ok && r.volume.byAmount && !r.volume.byUnits); });
run("volumen: monto solo desactivado con 0", { units: 200, gross: 130000, cost: 300, settings: { volume_amount_alone: 0 } }, r => { assert.ok(!r.volume.ok); });
run("marcadores aplican cifras cerradas", { gross: 375000, units: 5000, cost: 29.92, competitors: [comp("Sande", 39.2203, "competitivo")] }, r => {
  assert.equal(r.suggestion.value, 239400);
  const by = {}; r.markers.forEach(m => { by[m.key] = m; });
  assert.equal(by.comp_min.value, 196101.5); assert.equal(by.comp_min.apply, 196100);
  assert.equal(by.max.apply, 239400); assert.equal(by.min.apply, 333300); assert.equal(by.desired.apply, 254300);
  r.markers.forEach(m => { if (m.apply !== null) assert.equal(m.apply % 50, 0, m.key); });
});
run("un precio por competidor: cuenta la presentación más barata (granel)", { gross: 375000, units: 5000, cost: 29.92, competitors: [
  comp("Sande", 39.2203, "competitivo", { dominio: "sande.cl", producto_competencia_id: 1 }),
  comp("Sande", 53.1477, "competitivo", { dominio: "sande.cl", producto_competencia_id: 2 })] }, r => {
  assert.equal(r.counted.length, 1); assert.equal(r.counted[0].c.producto_competencia_id, 1);
  assert.ok(r.duplicates[2] && r.duplicates[2].indexOf("más barata") > 0);
  assert.equal(r.suggestion.value, 239400);
});
run("competidores distintos cuentan por separado", { gross: 375000, units: 5000, cost: 29.92, competitors: [
  comp("Sande", 39.2203, "competitivo", { dominio: "sande.cl" }),
  comp("DIMAFI", 53.1477, "competitivo", { dominio: "dimafi.cl" })] }, r => {
  assert.equal(r.counted.length, 2); assert.equal(r.suggestion.value, 254300);
});
run("codicioso aplica justo bajo él", { competitors: [comp("S", 38, "codicioso")] }, r => {
  const g = r.markers.filter(m => m.key === "greedy")[0]; assert.equal(g.value, 95000); assert.equal(g.apply, 94950);
});

// Precio oculto por el competidor: «Usar esta vez» (navegador) y «Usar siempre» (servidor: usar_oculto).
function hidden(extra) {
  return comp("Sande", 36, "competitivo", Object.assign({ producto_competencia_id: 5, oculto: true, usar_oculto: false,
    cuenta: false, motivo: "Precio oculto por el competidor.", motivo_oculto_usado: "",
    motivo_oculto_sin_usar: "Precio oculto por el competidor." }, extra || {}));
}
(function () {
  const H = (c, ov) => DA.withHiddenUse([c], ov)[0];
  let c = H(hidden(), {});
  assert.ok(!c.cuenta && c.oculto_uso === "no", "oculto sin marcar: no cuenta");
  c = H(hidden(), { 5: true });
  assert.ok(c.cuenta && c.motivo === "" && c.oculto_uso === "esta_vez", "usar esta vez: cuenta");
  c = H(hidden({ usar_oculto: true, cuenta: true, motivo: "" }), {});
  assert.ok(c.cuenta && c.oculto_uso === "siempre", "usar siempre: cuenta");
  c = H(hidden({ usar_oculto: true, cuenta: true, motivo: "" }), { 5: false });
  assert.ok(!c.cuenta && c.oculto_uso === "siempre_no_ahora" && c.motivo.indexOf("oculto") >= 0, "siempre, dejar de usar ahora: no cuenta");
  c = H(hidden({ motivo_oculto_usado: "Precio desactualizado (más de 90 días)." }), { 5: true });
  assert.ok(!c.cuenta && c.motivo.indexOf("desactualizado") >= 0, "usar esta vez no salta otros motivos (vigencia)");
  const plain = comp("DIMAFI", 40, "competitivo");
  assert.strictEqual(DA.withHiddenUse([plain], { 5: true })[0], plain, "los no ocultos no cambian");
  const original = hidden();
  DA.withHiddenUse([original], { 5: true });
  assert.ok(!original.cuenta, "no modifica la fila del servidor");
  console.log("ok - precio oculto: esta vez / siempre / dejar de usar ahora");
})();
run("oculto usado esta vez entra al cálculo", { competitors: DA.withHiddenUse([hidden()], { 5: true }) }, r => {
  assert.equal(r.status, "ok"); assert.equal(r.counted.length, 1);
});
run("oculto sin usar queda de referencia", { competitors: DA.withHiddenUse([hidden()], {}) }, r => {
  assert.equal(r.status, "no_info"); assert.equal(r.reference.length, 1);
});
