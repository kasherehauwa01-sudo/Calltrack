"""Offline browser smoke: actual MAX renderer and page CSS, no backend/production access."""
import re
from pathlib import Path
from playwright.sync_api import sync_playwright, expect

root = Path(__file__).resolve().parent.parent
html = (root / 'analizmop/index.html').read_text()
styles = '\n'.join(re.findall(r'<style[^>]*>(.*?)</style>', html, re.S))
styles += (root / 'analizmop/client-workspace.css').read_text()

def function(name):
    start = html.index('function ' + name + '(')
    end = html.find('\nfunction ', start + 1)
    return html[start:end if end >= 0 else None]

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/usr/bin/chromium', headless=True, args=['--no-sandbox'])
    for zone in ['UTC', 'Europe/Volgograd', 'America/Los_Angeles']:
        page = browser.new_page(timezone_id=zone, viewport={'width': 800, 'height': 700})
        page.set_content('<style>' + styles + '</style><div class="call-timeline" id="timeline"></div><div class="history-list" id="modal"></div>')
        page.add_script_tag(content="const escapeHtml=v=>String(v).replace(/[<>&\"]/g,c=>({'<':'&lt;','>':'&gt;','&':'&amp;','\"':'&quot;'}[c]));\n" + function('maxBusinessTime') + '\n' + function('renderMaxHistoryCard'))
        for incoming, outgoing in [(0, 1), (1, 0), (123, 456)]:
            for modal in [False, True]:
                target = '#modal' if modal else '#timeline'
                page.evaluate('''({target,modal,incoming,outgoing})=>{
                    document.querySelector(target).innerHTML=renderMaxHistoryCard({
                        date:'2026-10-19',last_event_at:'2026-10-18T17:30:00Z',
                        incoming_count:incoming,outgoing_count:outgoing},modal);
                }''', {'target': target, 'modal': modal, 'incoming': incoming, 'outgoing': outgoing})
                card = page.locator(target + ' .max-message')
                expect(card).to_contain_text(f'Входящие: {incoming}')
                expect(card).to_contain_text(f'Исходящие: {outgoing}')
                expect(card.locator('.call-datetime')).to_have_text('18.10.2026 · 20:30')
                bounds = card.evaluate('''card=>{
                    const box=card.getBoundingClientRect();
                    return {height:box.height, fits:card.scrollHeight<=card.clientHeight+1 && [...card.children].every(child=>{
                        const range=document.createRange();range.selectNodeContents(child);
                        return [...range.getClientRects()].every(r=>r.top>=box.top && r.bottom<=box.bottom && r.left>=box.left && r.right<=box.right);
                    }), last:card.lastElementChild.classList.contains('call-datetime'),
                    secondary:parseFloat(getComputedStyle(card.lastElementChild).fontSize)<parseFloat(getComputedStyle(card.querySelector('.call-type')).fontSize)};
                }''')
                assert bounds['fits'] and bounds['last'] and bounds['secondary'], (zone, modal, incoming, bounds)
                if not modal: assert bounds['height'] == 88, bounds
        page.close()
    browser.close()
print('MAX card browser smoke: all four lines fit; timeline 88px; modal; 0/1, 1/0, 123/456; three timezones OK')
