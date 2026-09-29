<?php
/**
 * Estados de cotización de venta (P0+P1).
 *
 * Flujo de este corte: borrador (draft) ↔ lista (listed).
 * Facturada (invoiced) queda reservada sin transición.
 *
 * Mapeo legado → plan:
 * - draft, borrador, rejected, expired, cancelled, canceled → draft
 * - sent, viewed, accepted, approved, lista, listed, converted → listed
 * - invoiced, billed, facturada → invoiced
 */
if (!defined('ABSPATH')) {
    exit;
}

class Riverso_Quote_Status {
    const DRAFT = 'draft';
    const LISTED = 'listed';
    const INVOICED = 'invoiced';

    public static function label($status) {
        switch (self::normalize_legacy((string) $status)) {
            case self::LISTED:
                return 'Lista';
            case self::INVOICED:
                return 'Facturada';
            default:
                return 'Borrador';
        }
    }

    public static function normalize_legacy($status) {
        $status = strtolower(trim((string) $status));
        $map = array(
            'draft' => self::DRAFT,
            'borrador' => self::DRAFT,
            'rejected' => self::DRAFT,
            'expired' => self::DRAFT,
            'cancelled' => self::DRAFT,
            'canceled' => self::DRAFT,
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
        );
        return isset($map[$status]) ? $map[$status] : self::DRAFT;
    }

    public static function can_transition($from, $to) {
        $from = self::normalize_legacy($from);
        $to = strtolower(trim((string) $to));
        if (!in_array($to, array(self::DRAFT, self::LISTED, self::INVOICED), true)) {
            return false;
        }
        if ($from === $to) {
            return true;
        }
        return ($from === self::DRAFT && $to === self::LISTED)
            || ($from === self::LISTED && $to === self::DRAFT);
    }

    public static function allowed_targets($status) {
        $status = self::normalize_legacy($status);
        if ($status === self::DRAFT) {
            return array(self::LISTED);
        }
        if ($status === self::LISTED) {
            return array(self::DRAFT);
        }
        return array();
    }

    public static function transition_label($target) {
        if ($target === self::LISTED) {
            return 'Pasar a lista';
        }
        if ($target === self::DRAFT) {
            return 'Volver a borrador';
        }
        return '';
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
