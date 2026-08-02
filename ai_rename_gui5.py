import re, json, base64, threading, io, hashlib, math
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor, as_completed
import tkinter as tk
from tkinter import filedialog, messagebox, ttk, simpledialog

import requests
try:
    from PIL import Image
    PIL_OK = True
except ImportError:
    PIL_OK = False

# =========================
# 1) IDE ÍRD BE A KULCSOD
# =========================
API_KEY = ""  # <-- ide tedd az sk-... kulcsot (NE oszd meg)

MODEL = "gpt-5-nano"
IMAGE_EXTS = {".png", ".jpg", ".jpeg", ".webp"}

# A kanonikus taglista JSON-t a program mellett keresi. A Tallózás gombbal
# bármelyik másik, kompatibilis taglista is betölthető.
TAG_DICTIONARY_FILENAME = "forme-taglista-vegleges-2026-08-02.json"
PROMPT_CACHE_KEY_PREFIX = "forme-ai-rename-canonical-v2"

DEFAULT_MAIN_CATS = ["Vicces", "Horgászat", "Család", "Ajándék", "Egyéb"]
DEFAULT_SUB_CATS  = ["Festő", "Szakmák / Mesterek", "Autós", "Apák napi", "Anyák napi", "Egyéb"]

WINDOWS_FORBIDDEN = r'<>:"/\\|?*'

# Fájlnévből tiltott szavak (ékezetfüggetlenül is)
BANNED_WORDS = ["póló", "polo", "poló", "pólós", "polos", "pólókat", "polokat"]

# Fájlnév max hossza (karakter)
FILENAME_MAX_CHARS = 55

# Párhuzamos feldolgozás alapértéke
DEFAULT_WORKERS = 4

# Kép max oldalhossza (px) API küldés előtt – 0 = eredeti méret
DEFAULT_MAX_SIDE = 768


def normalize_hu_basic(s: str) -> str:
    s = (s or "").lower()
    repl = {"á":"a","é":"e","í":"i","ó":"o","ö":"o","ő":"o","ú":"u","ü":"u","ű":"u"}
    for k, v in repl.items():
        s = s.replace(k, v)
    return s


def strip_banned_words(title: str, banned_words: list[str]) -> str:
    """Tiltott szavak eltávolítása (szóként), ékezetfüggetlen ellenőrzéssel."""
    if not title:
        return title

    parts = re.split(r"(\s+)", title)
    banned_norm = {normalize_hu_basic(w) for w in banned_words}

    out = []
    for part in parts:
        if part.isspace():
            out.append(part)
            continue

        token = re.sub(r"[^\wáéíóöőúüűÁÉÍÓÖŐÚÜŰ-]", "", part)
        if not token:
            out.append(part)
            continue

        if normalize_hu_basic(token) in banned_norm:
            continue

        out.append(part)

    res = "".join(out)
    res = re.sub(r"\s+", " ", res).strip()
    return res


def shorten_title_no_word_cut(text: str, max_chars: int = 55) -> str:
    t = (text or "").strip()
    t = re.sub(r"\s+", " ", t).strip()
    if not t:
        return "kep"

    if len(t) <= max_chars:
        return t

    words = t.split(" ")
    out = []
    cur_len = 0

    for w in words:
        if not out and len(w) > max_chars:
            return w

        add_len = len(w) if not out else (1 + len(w))
        if cur_len + add_len > max_chars:
            break

        out.append(w)
        cur_len += add_len

    res = " ".join(out).rstrip(" ,.-;:!?.")
    return res if res else words[0]


def clean_filename_title(title: str, max_len: int = 120) -> str:
    t = (title or "").strip()
    t = t.translate({ord(ch): "" for ch in WINDOWS_FORBIDDEN})
    t = re.sub(r"[\x00-\x1f]", " ", t)
    t = re.sub(r"\s+", " ", t).strip()
    t = t.rstrip(" .")
    if not t:
        t = "kep"
    if len(t) > max_len:
        t = t[:max_len].rstrip(" .")
    return t


def build_filename_from_title(title_hu: str) -> str:
    t = strip_banned_words(title_hu or "", BANNED_WORDS)
    t = shorten_title_no_word_cut(t, FILENAME_MAX_CHARS)
    t = clean_filename_title(t, max_len=FILENAME_MAX_CHARS)
    return t or "kep"


# ─────────────────────────────────────────────
# OPTIMALIZÁLT képküldés: resize + JPEG tömörítés
# ─────────────────────────────────────────────
def image_to_data_url(path: Path, max_side: int = 0) -> tuple[str, int]:
    """
    Visszaadja a data URL-t és a tömörített méret bájtban.
    max_side=0  → eredeti méret (PIL nélkül is működik)
    max_side>0  → PIL kell; átméretezi és JPEG-ként küldi
    """
    if max_side > 0 and PIL_OK:
        img = Image.open(path).convert("RGB")
        img.thumbnail((max_side, max_side), Image.LANCZOS)
        buf = io.BytesIO()
        img.save(buf, format="JPEG", quality=85)
        raw = buf.getvalue()
        b64 = base64.b64encode(raw).decode("ascii")
        return f"data:image/jpeg;base64,{b64}", len(raw)
    else:
        raw = path.read_bytes()
        ext = path.suffix.lower().lstrip(".")
        mime = "image/jpeg" if ext in ("jpg", "jpeg") else f"image/{ext}"
        b64 = base64.b64encode(raw).decode("ascii")
        return f"data:{mime};base64,{b64}", len(raw)


def unique_path_windows_style(target: Path) -> Path:
    if not target.exists():
        return target
    stem, suf = target.stem, target.suffix
    i = 2
    while True:
        cand = target.with_name(f"{stem} ({i}){suf}")
        if not cand.exists():
            return cand
        i += 1


def extract_output_text(out: dict) -> str:
    t = out.get("output_text")
    if isinstance(t, str) and t.strip():
        return t

    for o in out.get("output", []) or []:
        if not isinstance(o, dict):
            continue
        content = o.get("content")
        if content is None:
            continue
        if isinstance(content, dict):
            content = [content]
        if not isinstance(content, list):
            continue
        for c in content:
            if not isinstance(c, dict):
                continue
            if isinstance(c.get("text"), str) and c["text"].strip():
                return c["text"]
            if c.get("type") == "output_text" and isinstance(c.get("text"), str) and c["text"].strip():
                return c["text"]
    return ""


def safe_json_loads(s: str) -> dict:
    try:
        return json.loads(s)
    except Exception:
        m = re.search(r"\{.*\}", s, flags=re.S)
        if m:
            return json.loads(m.group(0))
        raise


def load_canonical_tags(path: Path) -> tuple[list[str], str]:
    """Betölt egy egyszerű tags JSON-t vagy a korábbi mg-tag-dictionary sémát.

    Támogatott formák:
      - {"tags": [{"label": "Anyának"}, ...]}
      - {"tags": ["Anyának", ...]}
      - {"dimensions": [{"terms": [{"label": "Anyának"}, ...]}]}
      - egy sima stringlista
    A visszaadott lista deduplikált, megjelenítési (kanonikus) nevekből áll.
    """
    if not path or not path.exists():
        raise FileNotFoundError(f"Taglista nem található: {path}")

    data = json.loads(path.read_text(encoding="utf-8"))
    values: list[str] = []

    def add_value(value):
        if isinstance(value, str):
            label = value.strip()
        elif isinstance(value, dict):
            label = str(value.get("label") or value.get("name") or value.get("value") or "").strip()
        else:
            label = ""
        if label and label not in values:
            values.append(label)

    if isinstance(data, list):
        for item in data:
            add_value(item)
    elif isinstance(data, dict):
        tags = data.get("tags")
        if isinstance(tags, list):
            for item in tags:
                add_value(item)

        dimensions = data.get("dimensions")
        if isinstance(dimensions, list):
            for dimension in dimensions:
                if not isinstance(dimension, dict):
                    continue
                for item in (dimension.get("terms") or []):
                    add_value(item)

        # Hasznos kompatibilitás egy esetleges {"terms": [...]} fájlhoz is.
        if not values and isinstance(data.get("terms"), list):
            for item in data["terms"]:
                add_value(item)

    if not values:
        raise ValueError("A taglista JSON-ban nem található címke.")

    version = "ismeretlen"
    if isinstance(data, dict):
        version = str(data.get("dictionary_version") or data.get("version") or version)
    return values, version


def build_schema(
    main_to_subs: dict,
    want_desc: bool,
    want_short: bool,
    want_main: bool,
    want_sub: bool,
    want_tags: bool,
    canonical_tags: list[str] | None = None,
    fixed_main: str | None = None
):
    props = {
        "title_hu": {"type": "string"},
        "confidence": {"type": "number", "minimum": 0, "maximum": 1},
    }
    required = ["title_hu", "confidence"]

    if want_desc:
        props["description"] = {"type": "string"}
        required.append("description")

    if want_short:
        props["short_description"] = {"type": "string"}
        required.append("short_description")

    if want_tags:
        props["tags"] = {
            "type": "array",
            "items": {"type": "string", "enum": sorted(set(canonical_tags or []))},
            "minItems": 0,
            "maxItems": 8
        }
        required.append("tags")

    if want_main or want_sub:
        mains = sorted([m for m in (main_to_subs or {}).keys() if (m or "").strip()]) or ["Egyéb"]
        all_subs = sorted({s for subs in (main_to_subs or {}).values() for s in (subs or []) if (s or "").strip()}) or ["Egyéb"]

        fixed_main = (fixed_main or "").strip() or None
        if fixed_main and fixed_main in main_to_subs:
            subs = [s.strip() for s in (main_to_subs.get(fixed_main) or []) if (s or "").strip()]
            sub_enum = subs or all_subs
        else:
            fixed_main = None
            sub_enum = all_subs

        cat_props = {}
        cat_req = []

        if want_main and not fixed_main:
            cat_props["main"] = {"type": "string", "enum": mains}
            cat_req.append("main")

        if want_sub:
            cat_props["sub"] = {"type": "string", "enum": sub_enum}
            cat_req.append("sub")

        props["categories"] = {
            "type": "object",
            "additionalProperties": False,
            "properties": cat_props,
            "required": cat_req
        }
        required.append("categories")

    return {
        "name": "img_meta_v2",
        "schema": {
            "type": "object",
            "additionalProperties": False,
            "properties": props,
            "required": required,
        }
    }


def enforce_category_mapping(meta: dict, main_to_subs: dict) -> tuple[dict, str]:
    note = ""
    cats = meta.get("categories")
    if not isinstance(cats, dict):
        return meta, note

    main = (cats.get("main") or "").strip()
    sub = (cats.get("sub") or "").strip()
    if not sub:
        return meta, note

    sub_to_mains: dict[str, list[str]] = {}
    for m, subs in (main_to_subs or {}).items():
        m = (m or "").strip()
        if not m:
            continue
        for s in (subs or []):
            s = (s or "").strip()
            if not s:
                continue
            sub_to_mains.setdefault(s, []).append(m)

    possible = sub_to_mains.get(sub, [])
    if not possible:
        note = f"⚠ sub nincs a mappingben: {sub}"
        return meta, note

    if len(possible) == 1:
        fixed_main = possible[0]
        if main != fixed_main and fixed_main:
            cats["main"] = fixed_main
            meta["categories"] = cats
            note = f"ℹ main javítva: '{main}' → '{fixed_main}' (sub='{sub}')"
        return meta, note

    if main in possible:
        return meta, note

    fixed_main = possible[0]
    cats["main"] = fixed_main
    meta["categories"] = cats
    note = f"⚠ main ütközés: '{main}' nem illik sub='{sub}'-hoz. Beállítva: '{fixed_main}'"
    return meta, note


def enforce_canonical_tags(meta: dict, canonical_tags: list[str]) -> tuple[dict, str]:
    """Biztonsági szűrés: a mentett JSON-ba csak a betöltött listából kerülhet tag."""
    allowed = {str(x).strip() for x in (canonical_tags or []) if str(x).strip()}
    incoming = meta.get("tags")
    if not isinstance(incoming, list):
        meta["tags"] = []
        return meta, "⚠ tags nem lista volt, üres listára állítva"

    kept = []
    removed = []
    seen = set()
    for item in incoming:
        value = str(item).strip()
        if value in allowed and value not in seen:
            kept.append(value)
            seen.add(value)
        elif value:
            removed.append(value)

    meta["tags"] = kept
    if removed:
        return meta, "⚠ Nem engedélyezett tagek kihagyva: " + ", ".join(removed[:5])
    return meta, ""


def enforce_fixed_main(meta: dict, main_to_subs: dict, fixed_main: str) -> tuple[dict, str]:
    note = ""
    fixed_main = (fixed_main or "").strip()
    if not fixed_main:
        return meta, note

    cats = meta.get("categories")
    if not isinstance(cats, dict):
        cats = {}
        meta["categories"] = cats

    cats["main"] = fixed_main

    allowed = [s.strip() for s in (main_to_subs.get(fixed_main) or []) if (s or "").strip()]
    sub = (cats.get("sub") or "").strip()

    if allowed:
        if sub not in allowed:
            fallback = "Egyéb" if "Egyéb" in allowed else allowed[0]
            cats["sub"] = fallback
            note = f"⚠ sub kívül esett: '{sub}' → '{fallback}'"
    else:
        note = f"⚠ fix main '{fixed_main}' alatt nincs alkategória"

    meta["categories"] = cats
    return meta, note


def call_openai_image_meta(
    data_url: str,
    schema: dict,
    instructions: str,
    prompt_cache_key: str = ""
) -> tuple[dict, dict]:
    body = {
        "model": MODEL,
        "instructions": instructions,
        "input": [{
            "role": "user",
            "content": [
                {"type": "input_text", "text": "Elemezd a képet és add vissza a kért sémát."},
                {"type": "input_image", "image_url": data_url}
            ]
        }],
        "text": {
            "format": {
                "type": "json_schema",
                "name": schema["name"],
                "schema": schema["schema"]
            }
        },
        "store": False
    }
    if prompt_cache_key:
        # A stabilis kulcs segít, hogy az azonos kategória- és tagpromptok
        # ugyanabba a prompt cache-be kerüljenek. A kép ettől még változó rész.
        body["prompt_cache_key"] = prompt_cache_key

    r = requests.post(
        "https://api.openai.com/v1/responses",
        headers={"Authorization": f"Bearer {API_KEY}", "Content-Type": "application/json"},
        data=json.dumps(body),
        timeout=120
    )

    if r.status_code >= 400:
        raise RuntimeError(f"HTTP {r.status_code}\n{r.text}")

    out = r.json()
    text = extract_output_text(out)
    if not text:
        raise RuntimeError("Nem találtam kimeneti szöveget:\n" + json.dumps(out, ensure_ascii=False)[:2000])

    meta = safe_json_loads(text)
    meta["version"] = 2
    meta["model"] = MODEL
    usage = out.get("usage") or {}
    input_details = usage.get("input_tokens_details") or {}
    cache_usage = {
        "cached_tokens": int(input_details.get("cached_tokens") or 0),
        "cache_write_tokens": int(input_details.get("cache_write_tokens") or 0),
    }
    return meta, cache_usage


class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("AI Képleíró + Átnevező – GPT-5 mini")
        self.geometry("1160x980")

        self.folder = tk.StringVar()
        self.dry_run = tk.BooleanVar(value=True)
        self.make_json = tk.BooleanVar(value=True)
        self.keep_original_name = tk.BooleanVar(value=False)

        # JSON mezők
        self.want_desc = tk.BooleanVar(value=True)
        self.want_short = tk.BooleanVar(value=True)
        self.want_main = tk.BooleanVar(value=True)
        self.want_sub = tk.BooleanVar(value=True)
        self.want_tags = tk.BooleanVar(value=True)

        # Kanonikus taglista
        default_tag_path = Path(__file__).with_name(TAG_DICTIONARY_FILENAME)
        self.tag_dictionary_path = tk.StringVar(value=str(default_tag_path) if default_tag_path.exists() else "")
        self.tag_dictionary_status = tk.StringVar(value="Nincs betöltve")
        self.canonical_tags: list[str] = []
        self.tag_dictionary_version = ""

        # kreatív + brand
        self.creative_mode = tk.BooleanVar(value=True)
        self.allow_brands = tk.BooleanVar(value=True)

        # fix fő kategória
        self.force_main = tk.BooleanVar(value=False)
        self.force_main_value = tk.StringVar(value="")

        # ÚJ: párhuzamos feldolgozás
        self.workers_var = tk.IntVar(value=DEFAULT_WORKERS)

        # ÚJ: képméret limit
        self.max_side_var = tk.IntVar(value=DEFAULT_MAX_SIDE)
        self.resize_enabled = tk.BooleanVar(value=PIL_OK)

        # ÚJ: cache (kész JSON-ok kihagyása)
        self.skip_existing = tk.BooleanVar(value=True)

        # ÚJ: főkategória sorrend = prioritás
        self.use_priority = tk.BooleanVar(value=False)

        # ---- Folder
        top = tk.Frame(self)
        top.pack(fill="x", padx=10, pady=(10, 6))
        tk.Label(top, text="Mappa:").pack(side="left")
        tk.Entry(top, textvariable=self.folder).pack(side="left", fill="x", expand=True, padx=8)
        tk.Button(top, text="Tallózás", command=self.pick_folder).pack(side="left")

        # ---- Options
        opt = tk.Frame(self)
        opt.pack(fill="x", padx=10, pady=6)
        tk.Checkbutton(opt, text="Dry run (ne nevezze át, csak mutassa)", variable=self.dry_run).pack(side="left")
        tk.Checkbutton(opt, text="JSON mentése (.json)", variable=self.make_json).pack(side="left", padx=18)
        tk.Checkbutton(opt, text="Kép nevének megtartása (csak JSON készül)", variable=self.keep_original_name).pack(side="left", padx=18)

        # ---- Teljesítmény / Token optimalizálás
        perf = tk.LabelFrame(self, text="⚡ Teljesítmény & Token-spórolás")
        perf.pack(fill="x", padx=10, pady=8)
        prow1 = tk.Frame(perf); prow1.pack(fill="x", padx=8, pady=(8,4))
        prow2 = tk.Frame(perf); prow2.pack(fill="x", padx=8, pady=(0,8))

        # Párhuzamos szálak
        tk.Label(prow1, text="Párhuzamos feldolgozás (szálak):").pack(side="left")
        tk.Spinbox(prow1, from_=1, to=16, textvariable=self.workers_var, width=4).pack(side="left", padx=6)
        tk.Label(prow1, text="(ajánlott: 3–6, OpenAI rate limittől függ)").pack(side="left", padx=8)

        # Cache
        tk.Checkbutton(prow1, text="Meglévő JSON-ok kihagyása (cache)", variable=self.skip_existing).pack(side="left", padx=20)

        # Képméret
        pil_state = "normal" if PIL_OK else "disabled"
        pil_hint = "" if PIL_OK else "  ⚠ Pillow nem telepítve – pip install Pillow"
        tk.Checkbutton(prow2, text="Kép átméretezés küldés előtt", variable=self.resize_enabled,
                       state=pil_state).pack(side="left")
        tk.Label(prow2, text="Max oldal (px):").pack(side="left", padx=(16,4))
        tk.Spinbox(prow2, from_=128, to=2048, increment=128, textvariable=self.max_side_var,
                   width=6, state=pil_state).pack(side="left")
        tk.Label(prow2, text="(512=kevés token, 1024=jobb minőség, 2048=eredeti)  " + pil_hint,
                 fg="gray").pack(side="left", padx=8)

        # ---- Fix main UI
        fix = tk.LabelFrame(self, text="Fix fő kategória (opcionális)")
        fix.pack(fill="x", padx=10, pady=8)
        row = tk.Frame(fix); row.pack(fill="x", padx=8, pady=8)
        tk.Checkbutton(row, text="Fő kategória fixálása", variable=self.force_main,
                       command=self.on_force_main_toggle).pack(side="left")
        tk.Label(row, text="Fix fő:").pack(side="left", padx=(18, 6))
        self.force_main_combo = ttk.Combobox(row, textvariable=self.force_main_value, state="disabled", width=30)
        self.force_main_combo.pack(side="left")
        tk.Label(row, text="(Ha be van kapcsolva: csak ehhez a főhöz tartozó alkategóriát választ.)").pack(side="left", padx=10)

        # ---- Naming behavior
        namebox = tk.LabelFrame(self, text="Név-képzés beállítások")
        namebox.pack(fill="x", padx=10, pady=8)
        r = tk.Frame(namebox); r.pack(fill="x", padx=8, pady=6)
        tk.Checkbutton(r, text="Kreatív mód (2-4 extra szó a fő felirat mellé)", variable=self.creative_mode).pack(side="left")
        tk.Checkbutton(r, text="Brand nevek engedése", variable=self.allow_brands).pack(side="left", padx=18)

        # ---- JSON toggles
        togg = tk.LabelFrame(self, text="Mi kerüljön a JSON-ba?")
        togg.pack(fill="x", padx=10, pady=8)
        row1 = tk.Frame(togg); row1.pack(fill="x", padx=8, pady=6)
        tk.Checkbutton(row1, text="Leírás", variable=self.want_desc).pack(side="left")
        tk.Checkbutton(row1, text="Rövid leírás", variable=self.want_short).pack(side="left", padx=14)
        tk.Checkbutton(row1, text="Fő kategória", variable=self.want_main).pack(side="left", padx=14)
        tk.Checkbutton(row1, text="Alkategória", variable=self.want_sub).pack(side="left", padx=14)
        tk.Checkbutton(row1, text="Címkék (tags)", variable=self.want_tags).pack(side="left", padx=14)

        # ---- Canonical tag dictionary
        tagbox = tk.LabelFrame(self, text="Kanonikus taglista (JSON)")
        tagbox.pack(fill="x", padx=10, pady=8)
        tagrow = tk.Frame(tagbox)
        tagrow.pack(fill="x", padx=8, pady=7)
        tk.Entry(tagrow, textvariable=self.tag_dictionary_path).pack(side="left", fill="x", expand=True)
        tk.Button(tagrow, text="Tallózás", command=self.pick_tag_dictionary).pack(side="left", padx=(6, 0))
        tk.Button(tagrow, text="Betöltés", command=self.load_tag_dictionary_ui).pack(side="left", padx=(6, 0))
        tk.Label(tagbox, textvariable=self.tag_dictionary_status, fg="gray").pack(anchor="w", padx=8, pady=(0, 6))

        # ---- Category relationship editor
        rel = tk.LabelFrame(self, text="Kategória kapcsolatok (Fő → Alkategóriák)")
        rel.pack(fill="x", padx=10, pady=8)

        self.cat_map_file = Path("category_map.json")
        self.main_to_subs: dict[str, list[str]] = {}

        wrap = tk.Frame(rel)
        wrap.pack(fill="both", expand=True, padx=8, pady=8)

        left = tk.Frame(wrap)
        left.pack(side="left", fill="both", expand=True)

        tk.Label(left, text="Fő kategóriák:").pack(anchor="w")
        self.main_list = tk.Listbox(left, height=8)
        self.main_list.pack(fill="both", expand=True)

        btns_main = tk.Frame(left)
        btns_main.pack(fill="x", pady=(6, 0))
        tk.Button(btns_main, text="+ Fő", command=self.add_main).pack(side="left")
        tk.Button(btns_main, text="Átnevez", command=self.rename_main).pack(side="left", padx=6)
        tk.Button(btns_main, text="Törlés", command=self.delete_main).pack(side="left")
        tk.Button(btns_main, text="▲", command=self.move_main_up, width=3).pack(side="left", padx=(14, 2))
        tk.Button(btns_main, text="▼", command=self.move_main_down, width=3).pack(side="left")

        prio_row = tk.Frame(left)
        prio_row.pack(fill="x", pady=(4, 0))
        tk.Checkbutton(
            prio_row,
            text="Sorrend = prioritás (ha több kategória illik, a feljebb lévő nyer)",
            variable=self.use_priority
        ).pack(side="left")

        right = tk.Frame(wrap)
        right.pack(side="left", fill="both", expand=True, padx=(12, 0))

        tk.Label(right, text="Alkategóriák (kijelölt főhöz):").pack(anchor="w")
        self.sub_list = tk.Listbox(right, height=8)
        self.sub_list.pack(fill="both", expand=True)

        btns_sub = tk.Frame(right)
        btns_sub.pack(fill="x", pady=(6, 0))
        tk.Button(btns_sub, text="+ Al", command=self.add_sub).pack(side="left")
        tk.Button(btns_sub, text="Átnevez", command=self.rename_sub).pack(side="left", padx=6)
        tk.Button(btns_sub, text="Törlés", command=self.delete_sub).pack(side="left")

        self.main_list.bind("<<ListboxSelect>>", lambda e: self.refresh_subs())
        self.load_category_map_or_defaults()
        self.load_tag_dictionary_ui(show_error=False)

        # ---- Controls
        ctl = tk.Frame(self)
        ctl.pack(fill="x", padx=10, pady=8)

        self.start_btn = tk.Button(ctl, text="▶ Start", command=self.start, bg="#2ecc71", fg="white",
                                   font=("", 10, "bold"), padx=10)
        self.start_btn.pack(side="left")

        self.pb_var = tk.IntVar(value=0)
        self.pb = ttk.Progressbar(ctl, maximum=100, variable=self.pb_var)
        self.pb.pack(side="left", fill="x", expand=True, padx=10)

        self.stat_label = tk.Label(ctl, text="", width=22, anchor="e")
        self.stat_label.pack(side="left")

        # ---- Log
        self.log = tk.Text(self, height=14)
        self.log.pack(fill="both", expand=True, padx=10, pady=(8, 10))

        tk.Label(
            self,
            text=f"Fájlnév: képen lévő fő szöveg rövidítve (max {FILENAME_MAX_CHARS} karakter), 'póló' nélkül. | PIL: {'✔' if PIL_OK else '✖ nincs telepítve'}",
        ).pack(anchor="w", padx=10, pady=(0, 10))

    # ---------------- FIX MAIN helpers ----------------

    def on_force_main_toggle(self):
        if self.force_main.get():
            self.force_main_combo.configure(state="readonly")
            vals = list(self.force_main_combo["values"])
            if vals and not self.force_main_value.get():
                self.force_main_value.set(vals[0])
        else:
            self.force_main_combo.configure(state="disabled")

    def refresh_force_main_values(self):
        mains = [m for m in self.main_to_subs.keys() if (m or "").strip()]
        self.force_main_combo["values"] = mains
        cur = (self.force_main_value.get() or "").strip()
        if cur and cur not in mains:
            self.force_main_value.set("")
        if self.force_main.get() and mains and not self.force_main_value.get():
            self.force_main_value.set(mains[0])

    # ---------------- category mapping UI ----------------

    def load_category_map_or_defaults(self):
        if self.cat_map_file.exists():
            try:
                data = json.loads(self.cat_map_file.read_text(encoding="utf-8"))
                self.main_to_subs = {str(k): [str(x) for x in (v or [])] for k, v in data.items()}
            except Exception:
                self.main_to_subs = {}

        if not self.main_to_subs:
            self.main_to_subs = {m: list(DEFAULT_SUB_CATS) for m in DEFAULT_MAIN_CATS}

        self.refresh_mains()
        self.refresh_force_main_values()
        if self.main_list.size() > 0:
            self.main_list.selection_set(0)
            self.refresh_subs()

    def save_category_map(self):
        try:
            self.cat_map_file.write_text(json.dumps(self.main_to_subs, ensure_ascii=False, indent=2), encoding="utf-8")
        except Exception:
            pass

    def refresh_mains(self):
        """Lista feltöltése – sorrend megőrzésével (nem sortol)."""
        sel_name = self.get_selected_main()
        self.main_list.delete(0, "end")
        for m in self.main_to_subs.keys():
            self.main_list.insert("end", m)
        # visszaállítja a kijelölést ha lehetséges
        items = list(self.main_to_subs.keys())
        if sel_name and sel_name in items:
            idx = items.index(sel_name)
            self.main_list.selection_set(idx)
            self.main_list.see(idx)

    def move_main_up(self):
        sel = self.main_list.curselection()
        if not sel:
            return
        idx = sel[0]
        if idx == 0:
            return
        keys = list(self.main_to_subs.keys())
        keys[idx - 1], keys[idx] = keys[idx], keys[idx - 1]
        self.main_to_subs = {k: self.main_to_subs[k] for k in keys}
        self.save_category_map()
        self.refresh_mains()
        self.main_list.selection_set(idx - 1)
        self.main_list.see(idx - 1)
        self.refresh_subs()

    def move_main_down(self):
        sel = self.main_list.curselection()
        if not sel:
            return
        idx = sel[0]
        keys = list(self.main_to_subs.keys())
        if idx >= len(keys) - 1:
            return
        keys[idx], keys[idx + 1] = keys[idx + 1], keys[idx]
        self.main_to_subs = {k: self.main_to_subs[k] for k in keys}
        self.save_category_map()
        self.refresh_mains()
        self.main_list.selection_set(idx + 1)
        self.main_list.see(idx + 1)
        self.refresh_subs()

    def get_selected_main(self):
        sel = self.main_list.curselection()
        if not sel:
            return None
        return self.main_list.get(sel[0])

    def refresh_subs(self):
        m = self.get_selected_main()
        self.sub_list.delete(0, "end")
        if not m:
            return
        for s in self.main_to_subs.get(m, []):
            self.sub_list.insert("end", s)

    def add_main(self):
        name = simpledialog.askstring("Új fő kategória", "Fő kategória neve:")
        if not name:
            return
        name = name.strip()
        if not name:
            return
        if name in self.main_to_subs:
            messagebox.showerror("Hiba", "Ilyen fő kategória már létezik.")
            return
        self.main_to_subs[name] = []
        self.save_category_map()
        self.refresh_mains()
        self.refresh_force_main_values()

    def rename_main(self):
        old = self.get_selected_main()
        if not old:
            return
        new = simpledialog.askstring("Fő kategória átnevezése", "Új név:", initialvalue=old)
        if not new:
            return
        new = new.strip()
        if not new or new == old:
            return
        if new in self.main_to_subs:
            messagebox.showerror("Hiba", "Ilyen fő kategória már létezik.")
            return
        self.main_to_subs[new] = self.main_to_subs.pop(old)
        self.save_category_map()
        self.refresh_mains()
        self.refresh_force_main_values()

    def delete_main(self):
        m = self.get_selected_main()
        if not m:
            return
        if messagebox.askyesno("Törlés", f"Törlöd ezt a fő kategóriát?\n\n{m}"):
            self.main_to_subs.pop(m, None)
            self.save_category_map()
            self.refresh_mains()
            self.refresh_subs()
            self.refresh_force_main_values()

    def add_sub(self):
        m = self.get_selected_main()
        if not m:
            messagebox.showerror("Hiba", "Előbb válassz fő kategóriát.")
            return
        name = simpledialog.askstring("Új alkategória", f"Alkategória neve ({m} alatt):")
        if not name:
            return
        name = name.strip()
        if not name:
            return
        subs = self.main_to_subs.setdefault(m, [])
        if name in subs:
            messagebox.showerror("Hiba", "Ilyen alkategória már van ennél a főnél.")
            return
        subs.append(name)
        self.save_category_map()
        self.refresh_subs()
        self.refresh_force_main_values()

    def rename_sub(self):
        m = self.get_selected_main()
        if not m:
            return
        sel = self.sub_list.curselection()
        if not sel:
            return
        old = self.sub_list.get(sel[0])
        new = simpledialog.askstring("Alkategória átnevezése", "Új név:", initialvalue=old)
        if not new:
            return
        new = new.strip()
        if not new or new == old:
            return
        subs = self.main_to_subs.get(m, [])
        if new in subs:
            messagebox.showerror("Hiba", "Ilyen alkategória már van ennél a főnél.")
            return
        subs[subs.index(old)] = new
        self.save_category_map()
        self.refresh_subs()
        self.refresh_force_main_values()

    def delete_sub(self):
        m = self.get_selected_main()
        if not m:
            return
        sel = self.sub_list.curselection()
        if not sel:
            return
        s = self.sub_list.get(sel[0])
        if messagebox.askyesno("Törlés", f"Törlöd ezt az alkategóriát?\n\n{m} → {s}"):
            subs = self.main_to_subs.get(m, [])
            if s in subs:
                subs.remove(s)
            self.save_category_map()
            self.refresh_subs()
            self.refresh_force_main_values()

    def get_category_map(self) -> dict:
        out = {}
        for m, subs in self.main_to_subs.items():
            m2 = (m or "").strip()
            if not m2:
                continue
            out[m2] = [s.strip() for s in (subs or []) if (s or "").strip()]
        return out

    # ---------------- main app flow ----------------

    def pick_tag_dictionary(self):
        p = filedialog.askopenfilename(
            title="Kanonikus taglista JSON kiválasztása",
            filetypes=[("JSON fájl", "*.json"), ("Minden fájl", "*.*")]
        )
        if p:
            self.tag_dictionary_path.set(p)
            self.load_tag_dictionary_ui()

    def load_tag_dictionary_ui(self, show_error: bool = True):
        raw_path = (self.tag_dictionary_path.get() or "").strip()
        if not raw_path:
            self.canonical_tags = []
            self.tag_dictionary_version = ""
            self.tag_dictionary_status.set("Nincs betöltve")
            return False

        try:
            values, version = load_canonical_tags(Path(raw_path))
            self.canonical_tags = values
            self.tag_dictionary_version = version
            self.tag_dictionary_status.set(f"Betöltve: {len(values)} kanonikus tag | verzió: {version}")
            return True
        except Exception as exc:
            self.canonical_tags = []
            self.tag_dictionary_version = ""
            self.tag_dictionary_status.set("Betöltési hiba")
            if show_error:
                messagebox.showerror("Taglista betöltési hiba", str(exc))
            return False

    def pick_folder(self):
        p = filedialog.askdirectory()
        if p:
            self.folder.set(p)

    def logline(self, s: str):
        self.log.insert("end", s + "\n")
        self.log.see("end")

    def start(self):
        if not API_KEY.startswith("sk-"):
            messagebox.showerror("Hiba", "Írd be a kulcsot a fájl tetején az API_KEY változóba (sk-... legyen).")
            return
        folder = self.folder.get().strip()
        if not folder:
            messagebox.showerror("Hiba", "Nincs kiválasztott mappa.")
            return

        cat_map = self.get_category_map()

        if self.want_tags.get():
            if not self.load_tag_dictionary_ui(show_error=True) or not self.canonical_tags:
                messagebox.showerror(
                    "Hiba",
                    "A címkékhez töltsd be a végleges kanonikus taglista JSON-t."
                )
                return

        if self.want_main.get() or self.want_sub.get():
            if not cat_map:
                messagebox.showerror("Hiba", "Adj meg legalább 1 fő kategóriát.")
                return
            if self.want_sub.get():
                has_any_pair = any(len(v) > 0 for v in cat_map.values())
                if not has_any_pair:
                    messagebox.showerror("Hiba", "Adj meg legalább 1 alkategóriát valamelyik fő alá.")
                    return

        if self.force_main.get():
            fixed = (self.force_main_value.get() or "").strip()
            if not fixed:
                messagebox.showerror("Hiba", "Fix fő kategória be van kapcsolva, de nincs kiválasztva.")
                return
            if fixed not in cat_map:
                messagebox.showerror("Hiba", f"A fix fő kategória nem létezik a listában: {fixed}")
                return

        if self.resize_enabled.get() and not PIL_OK:
            messagebox.showwarning("Figyelmeztetés", "Pillow nincs telepítve!\npip install Pillow\nÁtméretezés kikapcsolva.")
            self.resize_enabled.set(False)

        self.start_btn.config(state="disabled")
        self.pb_var.set(0)
        self.stat_label.config(text="")
        self.log.delete("1.0", "end")

        max_side = self.max_side_var.get() if self.resize_enabled.get() else 0
        workers = max(1, self.workers_var.get())

        self.logline(f"Modell: {MODEL}")
        self.logline(f"Mappa: {folder}")
        self.logline(f"Dry run: {self.dry_run.get()} | JSON: {self.make_json.get()} | Keep original: {self.keep_original_name.get()}")
        self.logline(f"Szálak: {workers} | Képméret: {'eredeti' if max_side == 0 else f'max {max_side}px'} | Cache: {self.skip_existing.get()}")
        self.logline(f"Kreatív: {self.creative_mode.get()} | Brand: {self.allow_brands.get()}")
        self.logline(f"JSON mezők: desc={self.want_desc.get()}, short={self.want_short.get()}, main={self.want_main.get()}, sub={self.want_sub.get()}, tags={self.want_tags.get()}")
        if self.want_tags.get():
            self.logline(f"Kanonikus taglista: {len(self.canonical_tags)} tag | verzió: {self.tag_dictionary_version}")
        if self.force_main.get():
            self.logline(f"FIX FŐ KATEGÓRIA: {self.force_main_value.get().strip()}")
        self.logline("-" * 90)

        threading.Thread(
            target=self.run_batch,
            args=(max_side, workers),
            daemon=True
        ).start()

    def run_batch(self, max_side: int, workers: int):
        import threading as _threading
        log_lock = _threading.Lock()

        folder = Path(self.folder.get().strip())
        files = sorted([p for p in folder.iterdir() if p.is_file() and p.suffix.lower() in IMAGE_EXTS])

        if not files:
            self.after(0, lambda: messagebox.showinfo("Info", "Nem találtam képfájlt a mappában."))
            self.after(0, lambda: self.start_btn.config(state="normal"))
            return

        # Cache szűrés
        if self.skip_existing.get():
            skipped = [f for f in files if f.with_suffix(".json").exists()]
            files = [f for f in files if not f.with_suffix(".json").exists()]
            if skipped:
                self.after(0, lambda n=len(skipped): self.logline(f"⏭  {n} kép kihagyva (már van JSON mellette)"))

        if not files:
            self.after(0, lambda: messagebox.showinfo("Info", "Minden kép már fel van dolgozva (JSON cache)."))
            self.after(0, lambda: self.start_btn.config(state="normal"))
            return

        cat_map = self.get_category_map()
        fixed_main = (self.force_main_value.get() or "").strip() if self.force_main.get() else None
        canonical_tags = list(self.canonical_tags)

        schema = build_schema(
            cat_map,
            self.want_desc.get(), self.want_short.get(),
            self.want_main.get(), self.want_sub.get(),
            self.want_tags.get(),
            canonical_tags=canonical_tags,
            fixed_main=fixed_main
        )

        # ---- Rövidített instructions ----
        parts = [
            f"title_hu: a kép domináns felirata, max {FILENAME_MAX_CHARS} karakter, teljes szavak, csak első betű nagy, NE tartalmazza: 'póló'."
        ]
        if self.creative_mode.get():
            parts.append("Adhatsz 2-4 hangulati szót a felirat mellé, de a fő szöveg maradjon felismerhető.")
        else:
            parts.append("Csak a felirat hű kivonatát add, extra szavak nélkül.")

        if self.allow_brands.get():
            parts.append("Brand nevek használhatók ha a képen szerepelnek.")
        else:
            parts.append("Kerüld a brand neveket.")

        if self.want_main.get() or self.want_sub.get():
            if fixed_main:
                allowed_subs = ", ".join(cat_map.get(fixed_main, []))
                parts.append(f"Fő kategória FIX: '{fixed_main}'. Alkategória csak ebből: [{allowed_subs}].")
            else:
                if self.use_priority.get():
                    ordered = list(self.main_to_subs.keys())
                    prio_str = " > ".join(f"'{m}'" for m in ordered)
                    parts.append(
                        f"categories: a megadott listákból válassz. "
                        f"Ha a kép több kategóriába is illik, ez a prioritási sorrend (előbb = fontosabb): {prio_str}. "
                        f"Például ha egy képen egyszerre van szakma és ünnepi utalás, a sorrendben előrébb lévő kategóriát válaszd."
                    )
                else:
                    parts.append("categories: a megadott listákból válassz, leginkább passzoló párt.")

        if self.want_tags.get():
            allowed_tags_text = ", ".join(canonical_tags)
            parts.append(
                "tags: 0-8 db kanonikus tag. KIZÁRÓLAG a következő, betöltött taglista pontos értékeiből válassz, "
                "ne írj át kisbetűre, ne adj hozzá ragozott alakot, SEO-mondatot, színt, stílust, konkrét idézetet vagy szabad szöveget. "
                f"Engedélyezett taglista: [{allowed_tags_text}] "
                "Ha nincs illő kanonikus tag, tags legyen üres tömb."
            )

        parts.append("Válaszolj kizárólag a JSON sémában.")
        instructions = " ".join(parts)

        total = len(files)
        cache_material = instructions + "\n" + json.dumps(
            schema, ensure_ascii=False, sort_keys=True, separators=(",", ":")
        )
        cache_digest = hashlib.sha256(cache_material.encode("utf-8")).hexdigest()[:20]
        # A dokumentáció kb. 15 kérés/percet javasol egy cache kulcshoz.
        # Nagyobb köteg esetén néhány stabil sharddal elkerüljük a túlzsúfolást.
        cache_shards = max(1, min(8, math.ceil(total / 15)))
        prompt_cache_keys = {
            img: f"{PROMPT_CACHE_KEY_PREFIX}:{cache_digest}:{idx % cache_shards}"
            for idx, img in enumerate(files)
        }
        done_count = [0]
        total_bytes_sent = [0]

        self.after(0, lambda: self.logline(
            f"Prompt cache: aktív | {cache_shards} kulcs | prefix={cache_digest}"
        ))

        def process_one(img: Path) -> tuple[Path, dict | None, str]:
            """Visszaad: (img, meta_or_None, log_üzenet)"""
            try:
                data_url, byte_size = image_to_data_url(img, max_side)
                total_bytes_sent[0] += byte_size

                meta, cache_usage = call_openai_image_meta(
                    data_url,
                    schema,
                    instructions,
                    prompt_cache_key=prompt_cache_keys[img]
                )
                tag_note = ""
                if self.want_tags.get():
                    meta, tag_note = enforce_canonical_tags(meta, canonical_tags)
                    meta["tag_dictionary_version"] = self.tag_dictionary_version

                if fixed_main and self.want_sub.get():
                    meta, note = enforce_fixed_main(meta, cat_map, fixed_main)
                elif (self.want_main.get() and self.want_sub.get()):
                    meta, note = enforce_category_mapping(meta, cat_map)
                else:
                    note = ""

                nice_title = build_filename_from_title(meta.get("title_hu", ""))

                if self.keep_original_name.get():
                    target_img = img
                    target_json = unique_path_windows_style(img.with_suffix(".json"))
                    meta["filename_base"] = img.stem
                else:
                    target_img = unique_path_windows_style(img.with_name(nice_title + img.suffix))
                    target_json = unique_path_windows_style(img.with_name(nice_title + ".json"))
                    meta["filename_base"] = target_img.stem

                meta["title_hu_short_for_filename"] = nice_title

                if self.make_json.get():
                    target_json.write_text(json.dumps(meta, ensure_ascii=False, indent=2), encoding="utf-8")

                if (not self.dry_run.get()) and (not self.keep_original_name.get()):
                    img.rename(target_img)

                conf = float(meta.get("confidence", 0))
                cat_info = ""
                if isinstance(meta.get("categories"), dict):
                    m = meta["categories"].get("main")
                    s = meta["categories"].get("sub")
                    parts_cat = [x for x in [m, s] if x]
                    if parts_cat:
                        cat_info = " | " + " / ".join(parts_cat)

                shown = target_img.name if not self.keep_original_name.get() else img.name
                kb = byte_size / 1024
                msg = f"   ✔ {shown}{cat_info} | conf={conf:.2f} | {kb:.0f} KB küldve"
                msg += (
                    f" | cache read={cache_usage.get('cached_tokens', 0)}"
                    f" write={cache_usage.get('cache_write_tokens', 0)}"
                )
                if note:
                    msg += f"\n   {note}"
                if tag_note:
                    msg += f"\n   {tag_note}"
                return img, meta, msg

            except Exception as e:
                return img, None, f"   ✖ Hiba ({img.name}): {e}"

        with ThreadPoolExecutor(max_workers=workers) as executor:
            futures = {executor.submit(process_one, img): img for img in files}

            for fut in as_completed(futures):
                img_path = futures[fut]
                _, _, log_msg = fut.result()

                done_count[0] += 1
                idx = done_count[0]
                progress = int(idx / total * 100)
                kb_total = total_bytes_sent[0] / 1024

                with log_lock:
                    self.after(0, lambda n=img_path.name, a=idx, t=total: self.logline(f"[{a}/{t}] {n}"))
                    self.after(0, lambda m=log_msg: self.logline(m))
                    self.after(0, lambda v=progress: self.pb_var.set(v))
                    self.after(0, lambda k=kb_total, a=idx: self.stat_label.config(
                        text=f"{a}/{total} | {k:.0f} KB küldve"))

        self.after(0, lambda: self.start_btn.config(state="normal"))
        self.after(0, lambda: messagebox.showinfo("Kész", f"Feldolgozás kész.\n{total} kép | {total_bytes_sent[0]/1024:.0f} KB elküldve"))


    # ---- Category map helpers (load/save/refresh) ----------------

    def load_category_map_or_defaults(self):
        if self.cat_map_file.exists():
            try:
                data = json.loads(self.cat_map_file.read_text(encoding="utf-8"))
                # dict insertion order megőrzése (Python 3.7+) – ez adja a prioritás sorrendet
                self.main_to_subs = {str(k): [str(x) for x in (v or [])] for k, v in data.items()}
            except Exception:
                self.main_to_subs = {}

        if not self.main_to_subs:
            self.main_to_subs = {m: list(DEFAULT_SUB_CATS) for m in DEFAULT_MAIN_CATS}

        self.refresh_mains()
        self.refresh_force_main_values()
        if self.main_list.size() > 0:
            self.main_list.selection_set(0)
            self.refresh_subs()

    def save_category_map(self):
        try:
            self.cat_map_file.write_text(json.dumps(self.main_to_subs, ensure_ascii=False, indent=2), encoding="utf-8")
        except Exception:
            pass


if __name__ == "__main__":
    App().mainloop()
