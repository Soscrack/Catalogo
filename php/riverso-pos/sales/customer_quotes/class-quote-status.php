<?php
/**
 * Estados de cotización de venta.
 *
 * Manuales: Borrador ↔ Aprobada; Borrador/Aprobada → Rechazada | Anulada; Rechazada/Anulada → Borrador.
 * Automático: Facturada (invoiced) cuando un documento de venta queda emitido o cerrado.
 * Solo Borrador es editable. Aprobada reserva stock (ver repositorio).
 *
 * Mapeo legado → plan:
 * - draft, borrador, expired → draft
 * - sent, viewed, accepted, approved, lista, listed, converted → listed (Aprobada)
 * - invoiced, billed, facturada → invoiced
 * - rejected, rechazada → rejected
 * - cancelled, canceled, anulada → cancelled
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Quote_Status {
    const DRAFT = 'draft';
    const LISTED = 'listed';
    const INVOICED = 'invoiced';
    const REJECTED = 'rejected';
    const CANCELLED = 'cancelled';

    public static function label($status) {
        switch (self::normalize_legacy((string) $status)) {
            case self::LISTED:
                return 'Aprobada';
            case self::INVOICED:
                return 'Facturada';
            case self::REJECTED:
                return 'Rechazada';
            case self::CANCELLED:
                return 'Anulada';
            default:
                return 'Borrador';
        }
    }

    public static function normalize_legacy($status) {
        $status = strtolower(trim((string) $status));
        $map = array(
            'draft' => self::DRAFT,
            'borrador' => self::DRAFT,
            'expired' => self::DRAFT,
            'sent' => self::LISTED,
            'viewed' => self::LISTED,
            'accepted' => self::LISTED,
            'approved' => self::LISTED,
            'lista' => self::LISTED,
            'listed' => self::LISTED,
            'converted' => self::LISTED,
            'invoiced' => self::INVOICED,
            'billed' => self::INVOICED,
            'facturada' => self::INVOICED,
            'rejected' => self::REJECTED,
            'rechazada' => self::REJECTED,
            'cancelled' => self::CANCELLED,
            'canceled' => self::CANCELLED,
            'anulada' => self::CANCELLED,
        );
        return isset($map[$status]) ? $map[$status] : self::DRAFT;
    }

    /** Solo el borrador admite cambios de contenido. */
    public static function is_editable($status) {
        return self::normalize_legacy($status) === self::DRAFT;
    }

    public static function can_transition($from, $to) {
        $from = self::normalize_legacy($from);
        $to = strtolower(trim((string) $to));
        if ($from === $to) {
            return true;
        }
        return in_array($to, self::allowed_targets($from), true);
    }

    /** Destinos manuales. Facturada solo la asigna la emisión de documentos. */
    public static function allowed_targets($status) {
        switch (self::normalize_legacy($status)) {
            case self::DRAFT:
                return array(self::LISTED, self::REJECTED, self::CANCELLED);
            case self::LISTED:
                return array(self::DRAFT, self::REJECTED, self::CANCELLED);
            case self::REJECTED:
            case self::CANCELLED:
                return array(self::DRAFT);
            default:
                return array();
        }
    }

    public static function transition_label($target, $from = null) {
        switch ($target) {
            case self::LISTED:
                return 'Aprobar';
            case self::REJECTED:
                return 'Rechazar';
            case self::CANCELLED:
                return 'Anular';
            case self::DRAFT:
                $from = $from === null ? null : self::normalize_legacy($from);
                return ($from === self::REJECTED || $from === self::CANCELLED) ? 'Reabrir' : 'Volver a borrador';
            default:
                return '';
        }
    }
}

class Riverso_Quote_Type {
    const VENTA = 'venta';
    const REFERENCIA = 'referencia';

    public static function label($type) {
        return $type === self::REFERENCIA ? 'Referencia' : 'Venta';
    }

    public static function normalize($type) {
        $type = strtolower(trim((string) $type));
        if ($type === '' || $type === self::VENTA) {
            return self::VENTA;
        }
        if ($type === self::REFERENCIA) {
            return self::REFERENCIA;
        }
        throw new Riverso_Quote_Exception('El tipo de cotización debe ser venta o referencia.');
    }
}
