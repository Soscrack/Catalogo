"""
UTILS.PY - Utilidades compartidas del proyecto
Funciones auxiliares para selección de archivos, validaciones, etc.
"""

from pathlib import Path
from typing import Optional
import sys


def select_xlsx_file(directory: str = 'data/processed', pattern: str = '*.xlsx') -> str:
    """
    Solicita al usuario seleccionar un archivo xlsx del directorio.
    
    Args:
        directory: Directorio donde buscar archivos
        pattern: Patrón de búsqueda (default: *.xlsx)
    
    Returns:
        Ruta completa del archivo seleccionado
    
    Raises:
        FileNotFoundError: Si no hay archivos que coincidan con el patrón
    """
    dir_path = Path(directory)
    
    if not dir_path.exists():
        raise FileNotFoundError(f"Directorio no existe: {directory}")
    
    # Buscar archivos que coincidan con el patrón
    files = sorted(dir_path.glob(pattern), key=lambda x: x.stat().st_mtime, reverse=True)
    
    if not files:
        raise FileNotFoundError(f"No hay archivos que coincidan con '{pattern}' en {directory}")
    
    # Si solo hay un archivo, usarlo automáticamente
    if len(files) == 1:
        print(f"✓ Usando archivo: {files[0].name}")
        return str(files[0])
    
    # Múltiples archivos: pedir selección
    print(f"\n📁 Archivos disponibles en {directory}:")
    for i, f in enumerate(files, 1):
        # Mostrar nombre y fecha de modificación
        mtime = f.stat().st_mtime
        from datetime import datetime
        mtime_str = datetime.fromtimestamp(mtime).strftime('%Y-%m-%d %H:%M:%S')
        size_mb = f.stat().st_size / (1024 * 1024)
        print(f"   {i}. {f.name} ({size_mb:.1f} MB, modificado: {mtime_str})")
    
    # Solicitar selección con validación
    while True:
        try:
            choice = input(f"\n¿Cuál archivo deseas usar? (1-{len(files)}): ").strip()
            idx = int(choice) - 1
            if 0 <= idx < len(files):
                selected = files[idx]
                print(f"✓ Seleccionado: {selected.name}")
                return str(selected)
            else:
                print(f"❌ Opción inválida. Debe ser entre 1 y {len(files)}")
        except ValueError:
            print("❌ Por favor ingresa un número válido")
        except KeyboardInterrupt:
            print("\n❌ Cancelado por el usuario")
            sys.exit(1)


def find_latest_xlsx(directory: str = 'data/processed', pattern: str = '*.xlsx') -> Optional[str]:
    """
    Encuentra el archivo xlsx más reciente en un directorio.
    
    Args:
        directory: Directorio donde buscar
        pattern: Patrón de búsqueda
    
    Returns:
        Ruta del archivo más reciente o None si no hay archivos
    """
    dir_path = Path(directory)
    
    if not dir_path.exists():
        return None
    
    files = sorted(dir_path.glob(pattern), key=lambda x: x.stat().st_mtime, reverse=True)
    
    if not files:
        return None
    
    return str(files[0])
