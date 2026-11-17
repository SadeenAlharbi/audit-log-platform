"""Build the product overview from product-overview.html (the single source).

    python3 render.py

Writes:
  ../product-overview.pdf             the PDF: the cover, then the body pages (header band and page numbers from
                                      the @page margin boxes, Chromium 131+), numbered from the first page after the cover
  ../product-overview-template.html   one self-contained file (images and fonts embedded) to edit in the
                                      browser and export with Ctrl+P -> Save as PDF

Needs: pip install playwright pypdf && playwright install chromium
"""
import base64
import pathlib
import re

from playwright.sync_api import sync_playwright
from pypdf import PdfWriter

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
    cover_pdf = HERE / "cover.pdf"
    body_pdf = HERE / "body.pdf"
    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()
        page.goto(SRC.as_uri())
        page.wait_for_load_state("networkidle")
        page.evaluate("document.fonts.ready")

        # Cover: full bleed, no header or footer.
        page.add_style_tag(content=".sheet:not(.cover-sheet) { display: none !important; }")
        page.pdf(path=str(cover_pdf), prefer_css_page_size=True, print_background=True)

        # Body: every sheet except the cover, so the page numbers (@page margin boxes) start after it.
        page.reload()
        page.wait_for_load_state("networkidle")
        page.evaluate("document.fonts.ready")
        page.add_style_tag(content=".cover-sheet { display: none !important; }")
        page.pdf(path=str(body_pdf), prefer_css_page_size=True, print_background=True)
        browser.close()

    writer = PdfWriter()
    for part in (cover_pdf, body_pdf):
        writer.append(str(part))
        part.unlink()
    writer.add_metadata({"/Title": TITLE, "/Author": "سدين الحربي"})
    with open(pdf, "wb") as fh:
        writer.write(fh)
    print(f"{pdf.relative_to(OUT.parent.parent)}: {len(writer.pages)} pages")
    print(f"{template.relative_to(OUT.parent.parent)}")


if __name__ == "__main__":
    main()
