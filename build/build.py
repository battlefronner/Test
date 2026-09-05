#!/usr/bin/env python3
"""
Baukasten: setzt die Website aus Bausteinen, Seitenkörpern und site.json zusammen.

    python3 build/build.py          Entwurfsmodus: leere Felder erscheinen rot markiert,
                                    Bearbeitungshinweise bleiben sichtbar
    python3 build/build.py --live   Livegang: Bearbeitungshinweise werden entfernt,
                                    leere Pflichtfelder brechen den Bau ab

Platzhalter in den Quellen:
    {{firma.inhaber}}                Wert aus site.json
    {{firma.funktion|Funktion}}      Wert, sonst roter Platzhalter mit diesem Text
    {{@kontakt.email|E-Mail}}       in Attributen: reiner Text, sonst Ersatztext (kein HTML)
    {{hauptsitz.strasse}}            Hauptsitz = erster Standort mit "hauptsitz": true
    {{#standorte}} … {{.name}} … {{/standorte}}      Schleife
    {{?recht.datenschutzbeauftragter}} … {{/?}}     nur wenn Feld gefüllt
    {{!recht.datenschutzbeauftragter}} … {{/!}}     nur wenn Feld leer
    <!--editor--> … <!--/editor-->   Hinweis nur im Entwurf, im Livegang entfernt
"""
import json, re, sys, os, html, hashlib, base64, datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
B = os.path.join(ROOT, 'build')
LIVE = '--live' in sys.argv

site = json.load(open(os.path.join(B, 'site.json'), encoding='utf-8'))
site['hauptsitz'] = next((s for s in site['standorte'] if s.get('hauptsitz')), site['standorte'][0])
site['jahr'] = str(datetime.date.today().year)

def lookup(path, ctx):
    """Löst a.b.c gegen ctx auf, danach gegen site. '.x' meint das Schleifenelement."""
    if path.startswith('.'):
        path = path[1:]; scopes = [ctx]
    else:
        scopes = [ctx, site] if ctx is not site else [site]
    for scope in scopes:
        cur = scope
        ok = True
        for part in path.split('.'):
            if isinstance(cur, dict) and part in cur:
                cur = cur[part]
            else:
                ok = False; break
        if ok:
            return cur
    return None

def hint_for(path):
    """Der Hinweistext zu einem Feld steht unter demselben Namen mit Unterstrich."""
    parts = path.lstrip('.').split('.')
    parent = lookup('.'.join(parts[:-1]), site) if len(parts) > 1 else site
    if isinstance(parent, dict):
        return parent.get('_' + parts[-1])
    return None

missing = []

def placeholder(path, label):
    text = label or hint_for(path) or path.upper()
    missing.append(path)
    return f'<span class="ph">[[{html.escape(text)}]]</span>'

def render(tpl, ctx=None):
    ctx = ctx if ctx is not None else site

    # Schleifen
    def loop(m):
        key, body = m.group(1), m.group(2)
        items = lookup(key, ctx) or []
        return ''.join(render(body, item) for item in items)
    tpl = re.sub(r'\{\{#([\w.]+)\}\}(.*?)\{\{/\1\}\}', loop, tpl, flags=re.DOTALL)

    # Bedingungen — verschachtelt erlaubt: es wird immer die innerste zuerst
    # aufgelöst (ein Rumpf darf keinen weiteren Öffner enthalten), so lange,
    # bis keine Bedingung mehr übrig ist.
    def filled(key): return lookup(key, ctx) not in (None, '', [], False)
    COND = r'\{\{\?([\w.]+)\}\}((?:(?!\{\{[?!][\w.]+\}\}).)*?)\{\{/\?\}\}'
    NOT  = r'\{\{!([\w.]+)\}\}((?:(?!\{\{[?!][\w.]+\}\}).)*?)\{\{/!\}\}'
    while True:
        new_tpl = re.sub(COND, lambda m: m.group(2) if filled(m.group(1)) else '', tpl, flags=re.DOTALL)
        new_tpl = re.sub(NOT,  lambda m: m.group(2) if not filled(m.group(1)) else '', new_tpl, flags=re.DOTALL)
        if new_tpl == tpl: break
        tpl = new_tpl

    # Werte in Attributen: reiner Text, leer -> Ersatztext, zählt trotzdem als fehlend
    def attr_value(m):
        key, fallback = m.group(1), m.group(2) or ''
        v = lookup(key, ctx)
        if v in (None, ''):
            missing.append(key)
            return html.escape(fallback, quote=True)
        return html.escape(str(v), quote=True)
    tpl = re.sub(r'\{\{@([\w.]+)(?:\|([^}]*))?\}\}', attr_value, tpl)

    # Werte
    def value(m):
        key, label = m.group(1), m.group(2)
        v = lookup(key, ctx)
        if v in (None, ''):
            return placeholder(key, label)
        if isinstance(v, bool):
            return 'ja' if v else 'nein'
        return html.escape(str(v)) if not str(v).startswith('<') else str(v)
    tpl = re.sub(r'\{\{([\w.]+)(?:\|([^}]*))?\}\}', value, tpl)
    return tpl

# --- Seiten -----------------------------------------------------------------
HEAD   = open(os.path.join(B, 'partials/head.html'), encoding='utf-8').read()
HEADER = open(os.path.join(B, 'partials/header.html'), encoding='utf-8').read()
FOOTER = open(os.path.join(B, 'partials/footer.html'), encoding='utf-8').read()

TRENN = {
 'Kleingedrucktes':'Klein&shy;gedrucktes','Berufshaftpflichtversicherung':'Berufs&shy;haftpflicht&shy;versicherung',
 'Verbraucherstreitbeilegung':'Verbraucher&shy;streit&shy;beilegung','Versicherungsvermittlung':'Versicherungs&shy;vermittlung',
 'Datenschutzbeauftragter':'Datenschutz&shy;beauftragter','Schlichtungsverfahren':'Schlichtungs&shy;verfahren',
 'Datenschutzrechtliche':'Datenschutz&shy;rechtliche','Datenschutzerklärung':'Datenschutz&shy;erklärung',
 'Außergerichtliche':'Außer&shy;gerichtliche','außergerichtliche':'außer&shy;gerichtliche','Widerspruchsrecht':'Widerspruchs&shy;recht',
 'Barrierefreiheit':'Barriere&shy;freiheit','Aufsichtsbehörde':'Aufsichts&shy;behörde','Verantwortlicher':'Verantwort&shy;licher',
 'Gesundheitsdaten':'Gesundheits&shy;daten','Berufsrechtliche':'Berufs&shy;rechtliche','Registerbehörden':'Register&shy;behörden',
 'Geltungsbereich':'Geltungs&shy;bereich','Kontaktformular':'Kontakt&shy;formular','Datensicherheit':'Daten&shy;sicherheit',
 'Erstinformation':'Erst&shy;information','Registereintrag':'Register&shy;eintrag','Streitbeilegung':'Streit&shy;beilegung',
 'miteinander':'mit&shy;einander'}
def hyphenate(s):
    def apply(m):
        tag, attrs, inner = m.group(1), m.group(2), m.group(3)
        if '&shy;' in inner: return m.group(0)
        for w, h in TRENN.items(): inner = inner.replace(w, h)
        return f'<{tag}{attrs}>{inner}</{tag}>'
    return re.sub(r'<(h1|h2|summary)([^>]*)>(.*?)</\1>', apply, s, flags=re.DOTALL)

def parse_meta(src):
    m = re.match(r'\s*<!--\s*page\s+(.*?)-->\s*', src, re.DOTALL)
    if not m: raise SystemExit("Seite ohne <!-- page … --> Kopf")
    meta = dict(re.findall(r'(\w+)="([^"]*)"', m.group(1)))
    return meta, src[m.end():]

pages_dir = os.path.join(B, 'pages')
built, sitemap = [], []
for rel in sorted(os.listdir(pages_dir) + [os.path.join('recht', f) for f in os.listdir(os.path.join(pages_dir, 'recht'))]):
    src_path = os.path.join(pages_dir, rel)
    if not os.path.isfile(src_path): continue
    meta, body = parse_meta(open(src_path, encoding='utf-8').read())

    head = HEAD
    for k in ('title', 'desc', 'path', 'robots'):
        head = head.replace('__' + k.upper() + '__', html.escape(meta.get(k, ''), quote=True))
    extra_head = ''
    if meta.get('head'):
        extra_head = open(os.path.join(B, 'extra', meta['head'] + '.html'), encoding='utf-8').read()
    extra_scripts = ''
    for s in filter(None, meta.get('scripts', '').split(',')):
        extra_scripts += open(os.path.join(B, 'extra', s.strip() + '.html'), encoding='utf-8').read()

    header = HEADER
    if meta.get('nav'):
        header = re.sub(rf'(<a class="nav__link" href="[^"]*" data-nav="{meta["nav"]}")', r'\1 aria-current="page"', header)

    page = ('<!DOCTYPE html>\n<html lang="de" class="no-js">\n<head>\n' + head + extra_head +
            '</head>\n<body>\n' + header + body + FOOTER + extra_scripts + '</body>\n</html>\n')
    if LIVE:
        page = re.sub(r'<!--editor-->.*?<!--/editor-->', '', page, flags=re.DOTALL)
    else:
        page = page.replace('<!--editor-->', '').replace('<!--/editor-->', '')

    page = render(page)

    page = hyphenate(page)
    out = os.path.join(ROOT, rel)
    os.makedirs(os.path.dirname(out), exist_ok=True)
    open(out, 'w', encoding='utf-8').write(page)
    built.append(rel)
    if 'noindex' not in meta.get('robots', ''):
        sitemap.append((meta['path'], meta.get('prio', '0.5'), meta.get('freq', 'yearly')))

# --- Sitemap ---------------------------------------------------------------
with open(os.path.join(ROOT, 'sitemap.xml'), 'w', encoding='utf-8') as f:
    f.write('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n')
    for p, prio, freq in sitemap:
        f.write(f'  <url><loc>{site["web"]["domain"]}{p}</loc><changefreq>{freq}</changefreq><priority>{prio}</priority></url>\n')
    f.write('</urlset>\n')

# --- CSP-Hash für Inline-Skripte der Startseite -------------------------------
idx = open(os.path.join(ROOT, 'index.html'), encoding='utf-8').read()
blocks = [b for b in re.findall(r'<script[^>]*>(.*?)</script>', idx, re.DOTALL) if b.strip()]
hashes = " ".join("'sha256-" + base64.b64encode(hashlib.sha256(b.encode('utf-8')).digest()).decode() + "'" for b in blocks)
for cfg in ('.htaccess', 'nginx.conf.example'):
    p = os.path.join(ROOT, cfg)
    s = open(p, encoding='utf-8').read()
    s = re.sub(r"script-src 'self'(?: 'sha256-[^']*')*;", f"script-src 'self'{(' ' + hashes) if hashes else ''};", s)
    open(p, 'w', encoding='utf-8').write(s)

# --- Bericht ----------------------------------------------------------------
uniq = sorted(set(missing))
print(f"{len(built)} Seiten gebaut ({'Livegang' if LIVE else 'Entwurf'}).")
if uniq:
    print(f"{len(missing)} Platzhalter aus {len(uniq)} leeren Feldern:")
    for m in uniq: print("   ", m)
    if LIVE:
        print("\nLivegang abgebrochen: Felder in build/site.json ausfüllen.")
        sys.exit(1)
else:
    print("Alle Felder gefüllt.")
