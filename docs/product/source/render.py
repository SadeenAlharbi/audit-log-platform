from playwright.sync_api import sync_playwright
import pathlib
html = pathlib.Path('body.html').resolve().as_uri()
footer = '''<div style="width:100%;font-size:7.5pt;color:#6C737F;padding:0 16mm;display:flex;justify-content:space-between;direction:rtl;font-family:'DejaVu Sans'">
<span>منصة سجلات التدقيق المركزية · وثيقة المنتج</span><span>صفحة <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>'''
header = '''<div style="width:100%;height:12mm;margin-top:-4mm;background:linear-gradient(90deg,#14573A,#1B8354);-webkit-print-color-adjust:exact;color:#fff;font-size:8pt;display:flex;align-items:center;justify-content:space-between;padding:0 16mm;direction:rtl;font-family:'DejaVu Sans'">
<span>وثيقة منتج منصة سجلات التدقيق المركزية</span><span style="direction:ltr">Audit Log Hub</span></div>'''
with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page()
    pg.goto(html); pg.wait_for_load_state('networkidle')
    pg.pdf(path='product-overview.pdf', format='A4', print_background=True, display_header_footer=True,
           header_template=header, footer_template=footer, margin={'top':'22mm','bottom':'18mm','left':'16mm','right':'16mm'})
    pg2 = b.new_page(); pg2.goto(pathlib.Path('cover.html').resolve().as_uri()); pg2.wait_for_load_state('networkidle')
    pg2.pdf(path='cover.pdf', width='210mm', height='297mm', print_background=True, margin={'top':'0','bottom':'0','left':'0','right':'0'})
    b.close()
from pypdf import PdfWriter
w = PdfWriter()
for f in ('cover.pdf', 'product-overview.pdf'):
    w.append(f)
w.add_metadata({'/Title': 'منصة سجلات التدقيق المركزية - وثيقة المنتج', '/Author': 'سادين الحربي'})
with open('final.pdf', 'wb') as fh:
    w.write(fh)
print('ok')
