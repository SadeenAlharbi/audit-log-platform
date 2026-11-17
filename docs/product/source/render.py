"""Build the product overview from product-overview.html (the single source).

    python3 render.py

Writes:
  ../product-overview.pdf             the PDF (Chromium print: A4, header band and page numbers from @page)
  ../product-overview-template.html   one self-contained file (images and fonts embedded) to edit in the
                                      browser and export with Ctrl+P -> Save as PDF

Needs: pip install playwright pypdf && playwright install chromium
"""
import base64
import pathlib
import re

from playwright.sync_api import sync_playwright
from pypdf import PdfReader, PdfWriter

HERE = pathlib.Path(__file__).resolve().parent
SRC = HERE / "product-overview.html"
OUT = HERE.parent
TITLE = "وثيقة تعريف المنتج — منصة سجلات التدقيق المركزية"
MIME = {".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png", ".woff2": "font/woff2"}


def standalone(html: str) -> str:
    """Inline every relative img/ and fonts/ reference as a data: URI."""
    def inline(match: re.Match) -> str:
        path = HERE / match.group(2)
        data = base64.b64encode(path.read_bytes()).decode()
        return f'{match.group(1)}data:{MIME[path.suffix.lower()]};base64,{data}{match.group(3)}'

    return re.sub(r'((?:src=|url\()")((?:img|fonts)/[^"]+)(")', inline, html)


def main() -> None:
    template = OUT / "product-overview-template.html"
    template.write_text(standalone(SRC.read_text(encoding="utf-8")), encoding="utf-8")

    pdf = OUT / "product-overview.pdf"
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()
        page.goto(SRC.as_uri())
        page.wait_for_load_state("networkidle")
        page.evaluate("document.fonts.ready")
        page.pdf(path=str(pdf), prefer_css_page_size=True, print_background=True)
        browser.close()

    writer = PdfWriter(clone_from=PdfReader(pdf))
    writer.add_metadata({"/Title": TITLE, "/Author": "سدين الحربي"})
    with open(pdf, "wb") as fh:
        writer.write(fh)
    print(f"{pdf.relative_to(OUT.parent.parent)}: {len(writer.pages)} pages")
    print(f"{template.relative_to(OUT.parent.parent)}")


if __name__ == "__main__":
    main()
