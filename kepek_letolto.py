"""
Kategória Kép Letöltő – Python GUI
Telepítés: pip install customtkinter pillow requests
Indítás:   python kepek_letolto.py
"""

import os, re, sys, time, threading, io, zipfile, urllib.parse
from html.parser import HTMLParser

import tkinter as tk
import tkinter.filedialog as fd
import tkinter.messagebox as mb

try:
    import customtkinter as ctk
    ctk.set_appearance_mode("dark")
    ctk.set_default_color_theme("blue")
except ImportError:
    import subprocess; subprocess.check_call([sys.executable,"-m","pip","install","customtkinter"])
    import customtkinter as ctk
    ctk.set_appearance_mode("dark")
    ctk.set_default_color_theme("blue")

try:
    from PIL import Image, ImageTk
    import requests
except ImportError:
    import subprocess; subprocess.check_call([sys.executable,"-m","pip","install","pillow","requests"])
    from PIL import Image, ImageTk
    import requests


try:
    from playwright.sync_api import sync_playwright
    PLAYWRIGHT_OK = True
except ImportError:
    PLAYWRIGHT_OK = False

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/124.0.0.0 Safari/537.36"
    ),
    "Accept-Language": "hu-HU,hu;q=0.9,en;q=0.8",
}
THUMB_SIZE = (116, 116)
GRID_COLS  = 5

SORT_OPTIONS = ["Legnépszerűbb", "Legújabb"]


class KepParser(HTMLParser):
    def __init__(self, base):
        super().__init__()
        self.base = base
        self.urls = []

    def handle_starttag(self, tag, attrs):
        if tag != "img":
            return
        a = dict(attrs)
        src = None
        srcset = a.get("srcset","") or a.get("data-srcset","")
        if srcset:
            best_w, best_src = 0, None
            for part in srcset.split(","):
                p = part.strip().split()
                try:
                    w = int(p[1].rstrip("w")) if len(p)>1 else 0
                    if w > best_w: best_w, best_src = w, p[0]
                except: pass
            src = best_src
        if not src:
            src = (a.get("src") or a.get("data-src") or
                   a.get("data-lazy-src") or a.get("data-original",""))
        if not src or src.startswith("data:"): return
        abs_url = urllib.parse.urljoin(self.base, src)
        if abs_url not in self.urls:
            self.urls.append(abs_url)


def playwright_get(url, sort_label=None):
    """Teljes JS renderelés Playwrighttel – virtuális lista kezeléssel.
    Lassan görget és menet közben gyűjti az img src-eket mielőtt a Vue eltüntetné őket.
    sort_label: ha meg van adva (pl. 'Legújabb'), a keresés előtt átállítja a rendezést."""
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True)
        page = browser.new_page(user_agent=HEADERS["User-Agent"],
                                extra_http_headers={"Accept-Language": HEADERS["Accept-Language"]})
        page.goto(url, wait_until="networkidle", timeout=30000)

        # Rendezés átállítása ha kérték
        if sort_label:
            try:
                # Először JS-sel kattintunk a selected-re (kinyitja a dropdownt)
                page.evaluate("""
                    () => {
                        const sel = document.querySelector('.custom-select .selected');
                        if (sel) sel.click();
                    }
                """)
                page.wait_for_timeout(500)
                # JS click-kel keressük meg a szöveg szerinti opciót –
                # a .selectHide osztály miatt Playwright nem kattint láthatatlnra
                clicked = page.evaluate(f"""
                    () => {{
                        const divs = document.querySelectorAll('.custom-select .items div');
                        for (const d of divs) {{
                            if (d.textContent.trim() === '{sort_label}') {{
                                d.click();
                                return true;
                            }}
                        }}
                        return false;
                    }}
                """)
                if clicked:
                    page.wait_for_load_state("networkidle", timeout=15000)
                    page.wait_for_timeout(1000)
            except Exception:
                pass  # ha nem sikerül, folytatjuk az alapértelmezett rendezéssel

        collected_srcs = set()

        def collect_current_imgs():
            srcs = page.evaluate("""
                () => Array.from(document.querySelectorAll('img[src]'))
                          .map(i => i.src)
                          .filter(s => s && !s.startsWith('data:'))
            """)
            for s in srcs:
                collected_srcs.add(s)

        # Lassan görgetünk végig az oldalon, minden lépésnél gyűjtünk
        total_h = page.evaluate("document.body.scrollHeight")
        step = 400   # px-enként lép
        pos = 0
        while pos <= total_h + step:
            page.evaluate(f"window.scrollTo(0, {pos})")
            page.wait_for_timeout(300)
            collect_current_imgs()
            pos += step
            # oldal magassága nőhet ha infinite scroll is van
            total_h = page.evaluate("document.body.scrollHeight")

        browser.close()

    # Fake HTML-t gyártunk a gyűjtött src-ekből hogy a meglévő parser feldolgozza
    html_parts = [f'<img src="{src}">' for src in collected_srcs]
    return "\n".join(html_parts)


def http_get(url, referer=None, stream=False):
    h = dict(HEADERS)
    h["Accept"] = "text/html,*/*;q=0.8"
    if referer:
        h["Referer"] = referer
        h["Accept"]  = "image/avif,image/webp,image/apng,image/*,*/*;q=0.8"
    return requests.get(url, headers=h, timeout=15)


def clean_name(url):
    name = url.split("/")[-1].split("?")[0]
    name = re.sub(r"[^\w.\-]", "_", name)
    if not re.search(r"\.(jpg|jpeg|png|gif|webp|avif|bmp)$", name, re.I):
        name += ".jpg"
    return name or "kep.jpg"


def make_thumb(data):
    try:
        img = Image.open(io.BytesIO(data)).convert("RGB")
        img.thumbnail(THUMB_SIZE, Image.LANCZOS)
        bg = Image.new("RGB", THUMB_SIZE, (22, 22, 32))
        ox = (THUMB_SIZE[0]-img.width)//2
        oy = (THUMB_SIZE[1]-img.height)//2
        bg.paste(img, (ox, oy))
        return ImageTk.PhotoImage(bg)
    except:
        return None


class App(ctk.CTk):
    def __init__(self):
        super().__init__()
        self.title("🖼  Kategória Kép Letöltő")
        self.geometry("920x800")
        self.minsize(780, 620)
        self.configure(fg_color="#0f0f13")

        self.found    = []
        self.selected = set()
        self._refs    = []

        self._build()

    # ── UI ──────────────────────────────────────────────────────────────────
    def _build(self):
        # fejléc
        hdr = ctk.CTkFrame(self, fg_color="#1a1a22", corner_radius=12)
        hdr.pack(fill="x", padx=16, pady=(14,6))
        ctk.CTkLabel(hdr, text="🖼  Kategória Kép Letöltő",
                     font=ctk.CTkFont(size=18, weight="bold"),
                     text_color="#fff").pack(side="left", padx=18, pady=10)

        # beállítások
        cfg = ctk.CTkFrame(self, fg_color="#1a1a22", corner_radius=12)
        cfg.pack(fill="x", padx=16, pady=6)

        url_row = ctk.CTkFrame(cfg, fg_color="transparent")
        url_row.pack(fill="x", padx=14, pady=(12,4))
        ctk.CTkLabel(url_row, text="URL", width=50,
                     text_color="#888", font=ctk.CTkFont(size=11)).pack(side="left")
        self.url_var = tk.StringVar()
        url_entry = ctk.CTkEntry(url_row, textvariable=self.url_var, height=36,
                     placeholder_text="https://pelda.com/kategoria/termekek",
                     fg_color="#0f0f13", border_color="#2a2a38")
        url_entry.pack(side="left", fill="x", expand=True, padx=(6,0))
        self.url_var.trace_add("write", self._on_url_change)

        filter_row = ctk.CTkFrame(cfg, fg_color="transparent")
        filter_row.pack(fill="x", padx=14, pady=(0,6))
        ctk.CTkLabel(filter_row, text="Kép URL tartalmaz", width=130,
                     text_color="#888", font=ctk.CTkFont(size=11)).pack(side="left")
        self.url_filter_var = tk.StringVar()
        self.url_filter_entry = ctk.CTkEntry(
            filter_row, textvariable=self.url_filter_var, height=30,
            placeholder_text="pl. /media/design/  (üresen hagyva = minden kép)",
            fg_color="#0f0f13", border_color="#2a2a38")
        self.url_filter_entry.pack(side="left", fill="x", expand=True, padx=(6,0))
        ctk.CTkLabel(filter_row, text="🔎", text_color="#5b6af0",
                     font=ctk.CTkFont(size=14)).pack(side="left", padx=(6,0))

        bottom_row = ctk.CTkFrame(cfg, fg_color="transparent")
        bottom_row.pack(fill="x", padx=14, pady=(0,6))

        # min méret
        for lbl, attr, default in [("Min. sz (px)","min_w","100"),
                                    ("Min. m (px)", "min_h","100")]:
            f = ctk.CTkFrame(bottom_row, fg_color="transparent")
            f.pack(side="left", padx=(0,12))
            ctk.CTkLabel(f, text=lbl, text_color="#888",
                         font=ctk.CTkFont(size=11)).pack(anchor="w")
            var = tk.StringVar(value=default)
            setattr(self, attr, var)
            ctk.CTkEntry(f, textvariable=var, width=80, height=30,
                         fg_color="#0f0f13", border_color="#2a2a38").pack()

        # checkboxok
        chk_f = ctk.CTkFrame(bottom_row, fg_color="transparent")
        chk_f.pack(side="left", padx=(8,0))
        self.v_icons  = tk.BooleanVar(value=True)
        self.v_dedup  = tk.BooleanVar(value=True)
        self.v_hires  = tk.BooleanVar(value=True)
        self.v_playwright = tk.BooleanVar(value=False)
        for text, var in [("Ikon szűrés", self.v_icons),
                          ("Duplikátum szűrés", self.v_dedup),
                          ("Legnagyobb forrás", self.v_hires)]:
            ctk.CTkCheckBox(chk_f, text=text, variable=var,
                            font=ctk.CTkFont(size=12), text_color="#aaa",
                            fg_color="#5b6af0", hover_color="#4a58e0",
                            checkmark_color="#fff").pack(side="left", padx=7)

        pw_color = "#5b6af0" if PLAYWRIGHT_OK else "#555"
        pw_txt   = "#aaa"   if PLAYWRIGHT_OK else "#555"
        self.pw_chk = ctk.CTkCheckBox(chk_f, text="JS renderelés (Playwright)",
                                       variable=self.v_playwright,
                                       font=ctk.CTkFont(size=12), text_color=pw_txt,
                                       fg_color=pw_color, hover_color="#4a58e0",
                                       checkmark_color="#fff",
                                       state="normal" if PLAYWRIGHT_OK else "disabled")
        self.pw_chk.pack(side="left", padx=7)

        # rendezés sor (polomania-specifikus)
        self.sort_row = ctk.CTkFrame(cfg, fg_color="transparent")
        self.sort_row.pack(fill="x", padx=14, pady=(0,6))
        ctk.CTkLabel(self.sort_row, text="Rendezés", width=130,
                     text_color="#888", font=ctk.CTkFont(size=11)).pack(side="left")

        self.sort_var = tk.StringVar(value="Legnépszerűbb")
        sort_frame = ctk.CTkFrame(self.sort_row, fg_color="transparent")
        sort_frame.pack(side="left")
        for opt in SORT_OPTIONS:
            ctk.CTkRadioButton(sort_frame, text=opt, variable=self.sort_var, value=opt,
                               font=ctk.CTkFont(size=12), text_color="#aaa",
                               fg_color="#5b6af0", hover_color="#4a58e0",
                               border_color="#555").pack(side="left", padx=10)

        self.sort_note = ctk.CTkLabel(self.sort_row,
                                      text="(csak Playwright módban érvényes)",
                                      text_color="#555", font=ctk.CTkFont(size=11))
        self.sort_note.pack(side="left", padx=(8,0))

        # alapból elrejtjük, csak polomania.hu-nál mutatjuk
        self.sort_row.pack_forget()

        # keresés gomb
        self.fetch_btn = ctk.CTkButton(cfg, text="🔍  Képek keresése",
                                        command=self._start_fetch, height=40,
                                        fg_color="#5b6af0", hover_color="#4a58e0",
                                        font=ctk.CTkFont(size=13, weight="bold"),
                                        corner_radius=8)
        self.fetch_btn.pack(fill="x", padx=14, pady=(0,14))

        # státusz + progress
        sp = ctk.CTkFrame(self, fg_color="transparent")
        sp.pack(fill="x", padx=16, pady=2)
        self.lbl_status = ctk.CTkLabel(sp, text="Várakozás...",
                                        text_color="#666", font=ctk.CTkFont(size=12))
        self.lbl_status.pack(side="left")
        self.lbl_counts = ctk.CTkLabel(sp, text="",
                                        text_color="#aaa", font=ctk.CTkFont(size=12))
        self.lbl_counts.pack(side="right")

        self.pb = ctk.CTkProgressBar(self, fg_color="#1a1a22",
                                      progress_color="#5b6af0", height=6)
        self.pb.pack(fill="x", padx=16, pady=(2,6))
        self.pb.set(0)

        # kijelölő gombok
        sel = ctk.CTkFrame(self, fg_color="transparent")
        sel.pack(fill="x", padx=16, pady=(0,6))
        for text, cmd in [("Összes kijelöl",self._sel_all),
                          ("Kijelölés törlése",self._sel_none),
                          ("Invertál",self._sel_invert)]:
            ctk.CTkButton(sel, text=text, command=cmd, height=28,
                          fg_color="#2a2a38", hover_color="#3a3a50",
                          font=ctk.CTkFont(size=12)).pack(side="left", padx=(0,6))

        # galéria
        self.gallery = ctk.CTkScrollableFrame(
            self, fg_color="#0f0f13", corner_radius=12,
            scrollbar_button_color="#2a2a38",
            scrollbar_button_hover_color="#3a3a50")
        self.gallery.pack(fill="both", expand=True, padx=16, pady=4)
        self.ginner = ctk.CTkFrame(self.gallery, fg_color="transparent")
        self.ginner.pack(fill="both", expand=True)

        # log
        self.log = ctk.CTkTextbox(self, height=88, fg_color="#0f0f13",
                                   text_color="#666", border_color="#2a2a38",
                                   border_width=1,
                                   font=ctk.CTkFont(family="Courier", size=11))
        self.log.pack(fill="x", padx=16, pady=(4,6))

        # letöltés
        dl_row = ctk.CTkFrame(self, fg_color="transparent")
        dl_row.pack(fill="x", padx=16, pady=(0,14))
        self.btn_zip = ctk.CTkButton(dl_row, text="⬇  ZIP letöltése",
                                      command=self._dl_zip, height=44,
                                      fg_color="#1db954", hover_color="#17a348",
                                      font=ctk.CTkFont(size=14, weight="bold"),
                                      state="disabled")
        self.btn_zip.pack(side="left", fill="x", expand=True, padx=(0,8))
        self.btn_folder = ctk.CTkButton(dl_row, text="📁  Mappába mentés",
                                         command=self._dl_folder, height=44,
                                         fg_color="#2a2a38", hover_color="#3a3a50",
                                         font=ctk.CTkFont(size=14, weight="bold"),
                                         state="disabled")
        self.btn_folder.pack(side="left", fill="x", expand=True)

    # ── helpers ─────────────────────────────────────────────────────────────
    def _log(self, msg):
        self.log.configure(state="normal")
        self.log.insert("end", msg+"\n")
        self.log.see("end")
        self.log.configure(state="disabled")

    def _status(self, msg):  self.lbl_status.configure(text=msg)
    def _progress(self, v):  self.pb.set(v)

    def _counts(self):
        self.lbl_counts.configure(
            text=f"Talált: {len(self.found)}  |  Kijelölve: {len(self.selected)}")

    def _clear_gallery(self):
        for w in self.ginner.winfo_children(): w.destroy()
        self._refs.clear()

    # ── URL auto-detect ──────────────────────────────────────────────────────
    def _on_url_change(self, *_):
        url = self.url_var.get().strip().lower()
        if "polomania.hu" in url:
            if not self.url_filter_var.get():
                self.url_filter_var.set("/media/design/")
            if PLAYWRIGHT_OK:
                self.v_playwright.set(True)
            self.sort_row.pack(fill="x", padx=14, pady=(0,6),
                               before=self.fetch_btn)
        else:
            if self.url_filter_var.get() == "/media/design/":
                self.url_filter_var.set("")
            self.sort_row.pack_forget()

    # ── keresés ─────────────────────────────────────────────────────────────
    def _start_fetch(self):
        url = self.url_var.get().strip()
        if not url: mb.showwarning("Hiba","Add meg az URL-t!"); return
        self._set_btns(False)
        self._clear_gallery()
        self.found.clear(); self.selected.clear()
        self.log.configure(state="normal"); self.log.delete("1.0","end"); self.log.configure(state="disabled")
        self._progress(0)
        threading.Thread(target=self._fetch_worker, args=(url,), daemon=True).start()

    def _fetch_worker(self, url):
        try:
            use_pw = self.v_playwright.get() and PLAYWRIGHT_OK
            sort_label = self.sort_var.get() if use_pw else None
            self.after(0, self._status, "Oldal letöltése (Playwright)..." if use_pw else "Oldal letöltése...")
            self.after(0, self._progress, 0.08)
            if use_pw:
                try:
                    html = playwright_get(url, sort_label=sort_label)
                    applied = sort_label if sort_label else "alapértelmezett"
                    self.after(0, self._log, f"✅ Playwright renderelés sikeres (rendezés: {applied})")
                except Exception as e:
                    self.after(0, self._log, f"⚠️ Playwright hiba, fallback requests-re: {e}")
                    html = http_get(url).text
            else:
                html = http_get(url).text
            self.after(0, self._progress, 0.3)
            self.after(0, self._status, "HTML feldolgozása...")

            p = KepParser(url); p.feed(html)
            urls = p.urls

            if self.v_icons.get():
                urls = [u for u in urls
                        if not u.endswith(".svg")
                        and not re.search(r"icon|logo|sprite|arrow|btn|pixel", u, re.I)]

            if self.v_dedup.get():
                seen = set(); deduped = []
                for u in urls:
                    if u not in seen: seen.add(u); deduped.append(u)
                urls = deduped

            url_filter = self.url_filter_var.get().strip()
            if url_filter:
                urls = [u for u in urls if url_filter in u]
                self.after(0, self._log, f"🔎 URL szűrő aktív: '{url_filter}' → {len(urls)} URL maradt")

            total = len(urls)
            self.after(0, self._status, f"{total} URL találva, bélyegképek generálása...")

            for i, img_url in enumerate(urls):
                try:
                    r = http_get(img_url, referer=url)
                    data = r.content
                    if len(data) < 600: continue
                    thumb = make_thumb(data)
                    if not thumb: continue
                    entry = {"url": img_url, "name": clean_name(img_url),
                             "data": data, "thumb": thumb}
                    self.found.append(entry)
                    idx = len(self.found)-1
                    self.selected.add(idx)
                    self.after(0, self._add_card, entry, idx)
                except Exception as e:
                    self.after(0, self._log, f"❌ {clean_name(img_url)}: {e}")

                self.after(0, self._progress, 0.3 + 0.68*(i+1)/total)
                self.after(0, self._status, f"Bélyegkép: {i+1}/{total}")
                self.after(0, self._counts)

            self.after(0, self._status, f"✅ {len(self.found)} kép megtalálva")
            self.after(0, self._progress, 1.0)
            self.after(0, self._counts)
            if self.found:
                self.after(0, lambda: self.btn_zip.configure(state="normal"))
                self.after(0, lambda: self.btn_folder.configure(state="normal"))
        except Exception as e:
            self.after(0, self._status, f"Hiba: {e}")
            self.after(0, self._log, f"HIBA: {e}")
        self.after(0, lambda: self.fetch_btn.configure(state="normal"))

    def _add_card(self, entry, idx):
        row = idx // GRID_COLS
        col = idx  % GRID_COLS

        card = ctk.CTkFrame(self.ginner, fg_color="#1a1a22",
                            corner_radius=9, border_width=2,
                            border_color="#5b6af0")
        card.grid(row=row, column=col, padx=5, pady=5, sticky="nsew")
        self.ginner.columnconfigure(col, weight=1)

        self._refs.append(entry["thumb"])
        ctk.CTkLabel(card, image=entry["thumb"], text="").pack(padx=4, pady=(6,2))

        short = entry["name"]
        if len(short) > 16: short = short[:14]+"…"
        ctk.CTkLabel(card, text=short, text_color="#777",
                     font=ctk.CTkFont(size=10)).pack(pady=(0,4))

        v = tk.BooleanVar(value=True)
        entry["v"] = v; entry["card"] = card

        def toggle(i=idx, var=v, c=card):
            if var.get(): self.selected.add(i);    c.configure(border_color="#5b6af0")
            else:          self.selected.discard(i); c.configure(border_color="#2a2a38")
            self._counts()

        chk = ctk.CTkCheckBox(card, text="", variable=v, command=toggle,
                               width=22, height=22, fg_color="#5b6af0",
                               hover_color="#4a58e0", border_color="#555")
        chk.place(relx=1.0, rely=0.0, anchor="ne", x=-4, y=4)

        for w in (card, card.winfo_children()[0]):
            w.bind("<Button-1>", lambda e, i=idx, var=v, c=card: (
                var.set(not var.get()), toggle(i, var, c)))

    # ── kijelölés ───────────────────────────────────────────────────────────
    def _sel_all(self):
        for i,e in enumerate(self.found):
            self.selected.add(i)
            if "v" in e: e["v"].set(True)
            if "card" in e: e["card"].configure(border_color="#5b6af0")
        self._counts()

    def _sel_none(self):
        self.selected.clear()
        for e in self.found:
            if "v" in e: e["v"].set(False)
            if "card" in e: e["card"].configure(border_color="#2a2a38")
        self._counts()

    def _sel_invert(self):
        for i,e in enumerate(self.found):
            new = i not in self.selected
            if new: self.selected.add(i)
            else:    self.selected.discard(i)
            if "v" in e: e["v"].set(new)
            if "card" in e: e["card"].configure(
                border_color="#5b6af0" if new else "#2a2a38")
        self._counts()

    # ── letöltés ─────────────────────────────────────────────────────────────
    def _dl_zip(self):
        if not self.selected: mb.showwarning("Hiba","Nincs kijelölt kép!"); return
        path = fd.asksaveasfilename(defaultextension=".zip",
                                    filetypes=[("ZIP","*.zip")],
                                    initialfile=f"kepek_{int(time.time())}.zip")
        if not path: return
        threading.Thread(target=self._save_zip, args=(path,), daemon=True).start()

    def _dl_folder(self):
        if not self.selected: mb.showwarning("Hiba","Nincs kijelölt kép!"); return
        folder = fd.askdirectory()
        if not folder: return
        threading.Thread(target=self._save_folder, args=(folder,), daemon=True).start()

    def _save_zip(self, path):
        items = [self.found[i] for i in sorted(self.selected)]
        self._set_btns(False)
        ok = fail = 0
        with zipfile.ZipFile(path, "w", zipfile.ZIP_STORED) as zf:
            for n, item in enumerate(items):
                self.after(0, self._status, f"ZIP: {n+1}/{len(items)} — {item['name']}")
                self.after(0, self._progress, (n+1)/len(items))
                try:
                    zf.writestr(item["name"], item["data"]); ok+=1
                    self.after(0, self._log, f"✅ {item['name']} ({len(item['data'])//1024} KB)")
                except Exception as e:
                    fail+=1; self.after(0, self._log, f"❌ {item['name']}: {e}")
        self.after(0, self._status, f"✅ Kész! {ok} kép{f', {fail} hiba' if fail else ''}")
        self.after(0, self._progress, 1.0)
        self.after(0, mb.showinfo, "Kész", f"{ok} kép mentve:\n{path}")
        self._set_btns(True)

    def _save_folder(self, folder):
        items = [self.found[i] for i in sorted(self.selected)]
        self._set_btns(False)
        ok = fail = 0; nc = {}
        for n, item in enumerate(items):
            self.after(0, self._status, f"Mentés: {n+1}/{len(items)} — {item['name']}")
            self.after(0, self._progress, (n+1)/len(items))
            try:
                fname = item["name"]
                nc[fname] = nc.get(fname,0)+1
                if nc[fname]>1:
                    b,e = fname.rsplit(".",1); fname=f"{b}_{nc[fname]}.{e}"
                with open(os.path.join(folder, fname),"wb") as f: f.write(item["data"])
                ok+=1; self.after(0, self._log, f"✅ {fname} ({len(item['data'])//1024} KB)")
            except Exception as e:
                fail+=1; self.after(0, self._log, f"❌ {item['name']}: {e}")
        self.after(0, self._status, f"✅ Kész! {ok} kép{f', {fail} hiba' if fail else ''}")
        self.after(0, self._progress, 1.0)
        self.after(0, mb.showinfo, "Kész", f"{ok} kép mentve:\n{folder}")
        self._set_btns(True)

    def _set_btns(self, enabled):
        s = "normal" if enabled else "disabled"
        self.after(0, lambda st=s: self.fetch_btn.configure(state=st))
        self.after(0, lambda st=s: self.btn_zip.configure(state=st))
        self.after(0, lambda st=s: self.btn_folder.configure(state=st))


if __name__ == "__main__":
    app = App()
    app.mainloop()
