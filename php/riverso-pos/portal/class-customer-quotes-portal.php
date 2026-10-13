<?php
/**
 * Portal de cotizaciones. La pantalla vive en el menú de WordPress
 * y reutiliza la plantilla de lista + cabecera.
 */

declare(strict_types=1);

final class Riverso_POS_Customer_Quotes_Portal {
    public function __construct(private Riverso_POS_Customer_Quote_Module $module) {
    }

    public function register(): void {
        $this->module->register();
    }
}
