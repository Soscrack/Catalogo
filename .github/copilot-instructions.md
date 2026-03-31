# Copilot Instructions - Catálogo Ferretería WooCommerce

## Build, Test, and Lint

```bash
# Install dependencies
pip install -r requirements.txt

# Run all tests
python test_pipeline.py

# Run a single test function
python -c "from test_pipeline import test_cleaner; test_cleaner()"

# Lint with black (if available)
black src/ --check
```

## Architecture

This is a deterministic ETL pipeline that transforms hardware store Excel catalogs into WooCommerce-ready product data with mandatory human review.

### Pipeline Flow

```
Excel Input → Loader → Cleaner → Patterns → Attributes → Grouping → Review → Human Stop
```

1. **loader.py** - Loads Excel without modifying original, creates checksums for audit
2. **cleaner.py** - Normalizes names, detects product families and brands via keywords
3. **patterns.py** - Extracts technical attributes using regex (diameter, length, material)
4. **attributes.py** - Validates extracted attributes, normalizes units (mm ↔ fractions)
5. **grouping.py** - Detects parent/variation relationships, generates hierarchical SKUs
6. **review.py** - Generates master format Excel for human review

### Key Entry Points

- `main.py` - Main pipeline orchestration (interactive or `--input path`)
- `revisor_gui.py` - Tkinter GUI for human review
- `revisor.py` - Console-based review tool
- `regenerate_catalog.py` - Regenerates catalog from reviewed data

### Data Flow

```
data/raw/         → Original Excel files (immutable audit copies)
data/processed/   → Cleaned, enriched data for human review
data/reviewed/    → Human-approved data ready for export
```

## Key Conventions

### No ML/AI for Extraction
All attribute extraction uses deterministic regex patterns defined in `config/rules.yaml`. This ensures:
- Same input = same output (reproducible)
- Every extraction is auditable and traceable

### Mandatory Human Review
The pipeline always stops before export. Products must have `Revisado_Humano: Sí` before WooCommerce export.

### WooCommerce Product Types
- `variable` - Parent product (NO price, NO stock)
- `variation` - Child product with price and stock (has `SKU_Parent`)
- `simple` - Standalone product

### Adding Product Families or Patterns
Edit `config/rules.yaml`:
```yaml
families:
  my_new_family:
    keywords: ['keyword1', 'keyword2']
    category: 'Category > Subcategory'

attributes:
  new_attr:
    patterns:
      - 'regex_pattern_here'
    label: 'Display Name'
```

### Module Classes
Each `src/` module exposes both a class and a function:
- Classes: `ExcelLoader`, `DataCleaner`, `PatternExtractor`, `AttributeValidator`, `ProductGrouper`, `ReviewFormatter`
- Functions: `load_products_excel()`, `clean_products()`, `extract_attributes()`, `validate_attributes()`, `group_products()`, `generate_master_format()`

### Confidence Scores
Every automatic extraction has a confidence score (0-100). Factors in `config/rules.yaml`:
- `nombre_limpio`: 0.3
- `atributos_detectados`: 0.2
- `marca_validada`: 0.2
- `sin_ambiguedad`: 0.3

### Logging
All modules use Python's `logging` with timestamped files in `logs/`. Enable debug:
```python
import logging
logging.basicConfig(level=logging.DEBUG)
```

## PDF Extraction Pipeline

Alternative input path for PDF catalogs (e.g., Mamut catalog) instead of Excel.

### Extraction Flow

```
PDF → LLMWhisper/PyMuPDF → Spatial Parser → JSON → Attribute Validator GUI → Master Excel
```

### Modules

- **catalogo_pdf.py** - Main extraction: parses PDF text into category tree + products with WooCommerce attributes
- **catalogo_spatial_parser.py** - Handles side-by-side table layouts in PDFs (left/right columns at position ~52-56)
- **llmwhisper_extract.py** - Optional LLMWhisper API integration for layout-preserving OCR
- **validador_atributos_catalogo.py** - GUI to validate extracted attributes (Accept/Keep Previous/Delete)

### Usage

```bash
# Extract from PDF (saves .txt alongside to avoid re-calling API)
python src/catalogo_pdf.py pdf/Catalogo.pdf data/extracted.json

# Or use existing .txt
python src/catalogo_pdf.py pdf/Catalogo.txt data/extracted.json

# Validate attributes with GUI
python validador_atributos_catalogo.py data/extracted.json data/processed/maestro.xlsx
```

### LLMWhisper Setup (Optional)

```bash
pip install llmwhisperer-client
```

Environment variables:
- `LLMWHISPERER_API_KEY` - API key for Unstract LLMWhisper
- `LLMWHISPERER_BASE_URL_V2` - API endpoint (default: `https://llmwhisperer-api.us-central.unstract.com/api/v2`)

If not installed, falls back to PyMuPDF (`pymupdf`).

### JSON Output Structure

```json
{
  "structure": { "Category": { "Subcategory": { "skus": ["SKU1", "SKU2"] } } },
  "products": { "SKU1": { "category_path": [...], "attributes": [...] } },
  "attributes_woocommerce": { "SKU1": { "Nombre del atributo 1": "...", "Valor(es) del atributo 1": "..." } }
}
```

### Spatial Parser Notes

The parser handles PDFs with two side-by-side tables. Key blacklists in `catalogo_spatial_parser.py`:
- `LOGO_BLACKLIST` - Brand logos to ignore (ESSVE, KNAPP, MAMUT, etc.)
- `SKU_BLACKLIST` - Words that look like SKUs but aren't (BALDE, CODIGO, T10, etc.)
