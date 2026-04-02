# Despliegue Riverso POS

## Archivo preparado

El plugin está comprimido y listo en:
```
C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\riverso-pos.zip
```

## Opción 1: WinSCP (Recomendado)

1. Abrir WinSCP
2. Conectar a:
   - Host: `72.61.37.37`
   - Puerto: `22`
   - Usuario: `root`
   - Contraseña: (la actual del servidor)

3. Subir `riverso-pos.zip` a `/tmp/`

4. Abrir terminal en WinSCP y ejecutar:
```bash
cd /var/www/vhosts/riverso.cl/httpdocs/wp-content/plugins
rm -rf riverso-pos
unzip /tmp/riverso-pos.zip
chown -R riverso.cl_1xybiw6rlcq:psacln riverso-pos
chmod -R 755 riverso-pos
rm /tmp/riverso-pos.zip

# Activar plugin
cd /var/www/vhosts/riverso.cl/httpdocs
sudo -u riverso.cl_1xybiw6rlcq wp plugin activate riverso-pos
```

## Opción 2: Línea de comandos

```powershell
# Desde PowerShell (ingresa contraseña cuando se solicite)
scp "C:\Users\jorge\Documents\GitHub\Simulacion\Catalogo\riverso-pos.zip" root@72.61.37.37:/tmp/

ssh root@72.61.37.37
```

Luego en el servidor:
```bash
cd /var/www/vhosts/riverso.cl/httpdocs/wp-content/plugins
rm -rf riverso-pos
unzip /tmp/riverso-pos.zip
chown -R riverso.cl_1xybiw6rlcq:psacln riverso-pos
chmod -R 755 riverso-pos

cd /var/www/vhosts/riverso.cl/httpdocs
sudo -u riverso.cl_1xybiw6rlcq wp plugin activate riverso-pos
```

## Opción 3: Panel Plesk

1. Ir a https://72.61.37.37:8443
2. Navegador de archivos → wp-content/plugins
3. Subir y extraer riverso-pos.zip
4. Ir a WordPress → Plugins → Activar "Riverso POS"

## Verificación

Después de activar, ir a:
- https://riverso.cl/wp-admin/admin.php?page=riverso-pos

Deberías ver el dashboard de Riverso POS.

## Estructura del plugin

```
riverso-pos/
├── riverso-pos.php          # Archivo principal
├── uninstall.php            # Limpieza al desinstalar
├── includes/                # Clases core
│   ├── class-activator.php  # Crea tablas y roles
│   ├── class-admin-menu.php # Menús
│   ├── class-ajax.php       # Endpoints AJAX
│   └── ...
├── modules/                 # Módulos funcionales
│   ├── invoices/            # Facturas DTE
│   ├── tasks/               # Tareas
│   └── warehouse/           # Bodega
├── templates/               # Vistas
│   ├── dashboard.php
│   ├── invoices.php
│   ├── tasks.php
│   ├── warehouse.php
│   └── codes.php
└── assets/                  # CSS/JS
```
