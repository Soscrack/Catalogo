-- Fase 83: Asistente de Descuento (3)
-- Prefijo real: {prefix} = wp_riverso_
--
-- Precio oculto por el competidor (Sande «ocultarPrecio»): la API igual entrega el precio, pero
-- no cuenta para descuentos salvo que alguien elija «Usar siempre» en el asistente.
-- «Usar esta vez» vive solo en el navegador (documento abierto) y no toca la base.
-- El refresco nocturno no actualiza estas columnas.
-- El activador PHP aplica esto de forma idempotente
-- (Riverso_POS_Activator::create_phase83_competencia_usar_oculto).

ALTER TABLE `{prefix}competencia_productos`
  ADD COLUMN IF NOT EXISTS `usar_oculto` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `usar_oculto_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `usar_oculto_por` BIGINT UNSIGNED NULL DEFAULT NULL;
