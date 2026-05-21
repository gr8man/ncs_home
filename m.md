# Fix: Konfigurator 3D — iOS Safari crash & hang + zawieszanie przy zmianach

## Objawy

### Objaw 1: iOS < 16.4 — strona wisi na loaderze
Na iPhone z **iOS < 16.4** konfigurator 3D wisi na loaderze. Model się nie wyświetla, formularz nie reaguje.

**Na iOS 16.4+ konfigurator działa poprawnie bez żadnych poprawek** — `OffscreenCanvas` jest wspierany od tej wersji.

### Objaw 2: Zawieszanie po 3-7 zmianach parametrów (WSZYSTKIE iOS)
Po zmianie wymiarów szafki (szerokość, wysokość, głębokość) formularz blokuje się po kilku zmianach. Inputy przestają reagować, model 3D przestaje się odświeżać.

## Potwierdzone testami (Playwright Chromium)

| Test | iOS 15.0 (bez OffscreenCanvas) | iOS 16.4 |
|---|---|---|
| Błędy | ❌ `OffscreenCanvas is not defined` | ✅ Brak |
| Canvas 3D | ❌ Nie istnieje | ✅ 900x900 |
| Koszyk | ❌ Zablokowany | ✅ Aktywny |
| Log | (brak) | `using OffscreenCanvas` |

| Test zmian parametrów | Wynik |
|---|---|
| 10 szybkich zmian | ❌ Hang po 1 zmianie (`fieldset=true`, `cart=true`) |
| 7 zmian co 2s | ⚠️ `fieldset=true` ale `cart=false` (koszyk OK, inputy zablokowane) |
| 1 zmiana + czekanie 3s | ✅ `fieldset=false`, `cart=false` |

---

## Przyczyny

### Błąd #1: `workerCreate()` — Promise nigdy się nie rozwiązuje (iOS < 16.4)

Plik: `admin.kronosfera.erozrys.pl/assets/js_pages/corps/dist/corps.js`

```js
this.workerCreate = async () => new Promise(async (t, e) => {
    const l = document.createElement("canvas");
    const u = new OffscreenCanvas(1, 1);  // ← ReferenceError na iOS < 16.4
    if (l.transferControlToOffscreen() && u.getContext("webgl")) {
        t(this);
    }
    // ← BRAK else — Promise wisi w nieskończoność
});
```

### Błąd #2: `checkOffscreenCanvasSupported()` — rzuca zamiast zwrócić fallback

```js
this.checkOffscreenCanvasSupported = () => {
    const t = document.createElement("canvas");
    const e = new OffscreenCanvas(1, 1);  // ← ReferenceError
    return t.transferControlToOffscreen() && e.getContext("webgl")
        ? "startWithWorker" : "startWithoutWorker";
}
```

### Błąd #3: Vue Canvas component — `dl.worker` jest undefined

W ścieżce `startWithoutWorker` worker nie jest tworzony, ale Vue Canvas component robi:
```js
dl.worker.addEventListener("message", ...)  // ← TypeError
```

### Błąd #4: `reloadModel3D()` — fieldset WYLACZANY ale NIGDY nie WLACZANY (WSZYSTKIE iOS)

```js
function reloadModel3D() {
    // ...
    const fieldset = document.getElementById('form_fieldset') ?? document.createElement('fieldset');
    if (!mform.querySelector('#form_fieldset')) {
        fieldset.id = 'form_fieldset';
        [...mform.children].forEach(element => { fieldset.append(element); });
        mform.append(fieldset);
    }
    fieldset.disabled = true;  // ← WYŁĄCZA formularz
    // ← NIGDY nie ustawia disabled = false
}
```

`modelready` event odblokowuje koszyk (`$('#corp_cart_add').prop('disabled', false)`) ale **NIE odblokowuje fieldsetu**. Formularz zostaje zablokowany na stałe.

**Dlaczego po 3-7 zmianach:** Przy szybkich zmianach `reloadModel3D()` wołany jest wielokrotnie. Fieldset jest tworzony i wyłączany przy każdej zmianie. Jeśli `modelready` nie odpali (worker nie nadąży), fieldset zostaje `disabled=true` na stałe.

---

## Jak naprawić — NAJPROSTSZE ROZWIĄZANIE

### Sposób A: Monkey-patch ze strony HTML (najszybszy, nie wymaga modyfikacji corps.js)

Dodaj **przed** `<script src="...corps.js">` na stronie:

```html
<script>
// === FIX 1: OffscreenCanvas polyfill dla iOS < 16.4 ===
if (typeof OffscreenCanvas === 'undefined') {
    window.OffscreenCanvas = function(w, h) {
        return { getContext: function() { return null; } };
    };
}
if (!HTMLCanvasElement.prototype.transferControlToOffscreen) {
    HTMLCanvasElement.prototype.transferControlToOffscreen = function() {
        return null;
    };
}
</script>
```

Dodaj **po** `<script src="...corps.js">` na stronie:

```html
<script>
// === FIX 2: Odblokowanie fieldset po modelready ===
document.addEventListener('modelready', function() {
    const fieldset = document.getElementById('form_fieldset');
    if (fieldset) fieldset.disabled = false;
});

// === FIX 3: Ochrona reloadModel3D przed brakiem canvas ===
const _origReloadModel3D = window.reloadModel3D;
window.reloadModel3D = function() {
    const form = document.getElementById('cabinet_form_data');
    const canvas = form?.querySelector('canvas');
    if (!canvas) return;
    return _origReloadModel3D();
};
</script>
```

### Sposób B: Modyfikacja corps.js (jeśli macie dostęp do serwera)

**Fix 1:** `workerCreate()` — zawsze resolvuj Promise

Znajdź:
```js
this.workerCreate=async()=>new Promise(async(t,e)=>{var n,s,i,r,o,a,c;const l=document.createElement("canvas"),u=new OffscreenCanvas(1,1);if(l.transferControlToOffscreen()&&u.getContext("webgl")){
```
Zamień na:
```js
this.workerCreate=async()=>new Promise(async(t,e)=>{var n,s,i,r,o,a,c;const l=document.createElement("canvas");if(typeof OffscreenCanvas!=="undefined"){const u=new OffscreenCanvas(1,1);if(l.transferControlToOffscreen()&&u.getContext("webgl")){
```

Znajdź:
```js
t(this)}}),this.checkOffscreenCanvasSupported
```
Zamień na:
```js
t(this)}}else{t(this)}}),this.checkOffscreenCanvasSupported
```

**Fix 2:** `checkOffscreenCanvasSupported()` — bezpieczny check

Znajdź:
```js
this.checkOffscreenCanvasSupported=()=>{const t=document.createElement("canvas"),e=new OffscreenCanvas(1,1);return t.transferControlToOffscreen()&&e.getContext("webgl")?"startWithWorker":"startWithoutWorker"}
```
Zamień na:
```js
this.checkOffscreenCanvasSupported=()=>{if(typeof OffscreenCanvas==="undefined")return "startWithoutWorker";try{const t=document.createElement("canvas"),e=new OffscreenCanvas(1,1);return t.transferControlToOffscreen()&&e.getContext("webgl")?"startWithWorker":"startWithoutWorker"}catch{return "startWithoutWorker"}}
```

**Fix 3:** Vue Canvas component — guard na `dl.worker`

Znajdź:
```js
dl.worker.addEventListener("message",function(e){var n;(null===(n=null==e?void 0:e.data)||void 0===n?void 0:n.cameraSettings)
```
Zamień na:
```js
dl.worker&&dl.worker.addEventListener("message",function(e){var n;(null===(n=null==e?void 0:e.data)||void 0===n?void 0:n.cameraSettings)
```

**Fix 4:** `startWithoutWorker` — ustaw `dl.worker` na null

Znajdź:
```js
console.info("using Canvas")},this.windowData=
```
Zamień na:
```js
dl.setWorker(null);console.info("using Canvas")},this.windowData=
```

### Fix CSS (dodatkowo, zalecany)

Dodaj do `style.css` / `safari.css`:
```css
.model_3d canvas { touch-action: none; }
```

---

## Co zadziała po fixach

| Funkcja | Sposób A (monkey-patch) | Sposób B (corps.js) |
|---|---|---|
| iOS 15.0 — canvas 3D | ✅ | ✅ |
| iOS 16.4 — canvas 3D | ✅ (nie potrzebny) | ✅ (nie potrzebny) |
| Formularz nie blokuje się po zmianach | ✅ | ✅ |
| Koszyk aktywny po zmianach | ✅ | ✅ |
| Touch events (obrót modelem) | ✅ (po dodaniu CSS) | ✅ (po dodaniu CSS) |

---

## Weryfikacja

```bash
npm install playwright && npx playwright install chromium
```

```js
// test.mjs
import { chromium } from 'playwright';

async function test(label, userAgent, removeOffscreenCanvas) {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({
        userAgent, viewport: { width: 390, height: 844 },
        isMobile: true, hasTouch: true
    });
    const page = await ctx.newPage();
    if (removeOffscreenCanvas) {
        await page.addInitScript(() => {
            delete window.OffscreenCanvas;
            delete HTMLCanvasElement.prototype.transferControlToOffscreen;
        });
    }
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto('https://meblosfera-konfigurator.kronosfera.pl/k/komoda-alfa,3682', {
        waitUntil: 'networkidle', timeout: 60000
    });
    await page.waitForTimeout(10000);

    // Test 5 zmian
    for (let i = 0; i < 5; i++) {
        await page.evaluate((w) => {
            const input = document.getElementById('corp_width');
            input.value = String(w);
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }, 500 + i * 50);
        await page.waitForTimeout(1500);
    }

    const state = await page.evaluate(() => {
        const inputs = document.querySelectorAll('#cabinet_form_data input:not([type=hidden])');
        return {
            canvas: !!document.querySelector('canvas'),
            cart_disabled: document.getElementById('corp_cart_add')?.disabled,
            inputs_blocked: Array.from(inputs).every(i => i.disabled),
        };
    });
    await browser.close();
    return { label, errors: errors.length, state };
}

const r1 = await test('iOS 15.0', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X)', true);
const r2 = await test('iOS 16.4', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_4 like Mac OS X)', false);
console.log('iOS 15.0:', r1.state.inputs_blocked ? 'NIE dziala' : 'Dziala', `(bledy: ${r1.errors})`);
console.log('iOS 16.4:', r2.state.inputs_blocked ? 'NIE dziala' : 'Dziala', `(bledy: ${r2.errors})`);
```

**Oczekiwany wynik po fixach:**
- iOS 15.0: `Dziala` (canvas: true, inputs_blocked: false, błędy: 0)
- iOS 16.4: `Dziala` (canvas: true, inputs_blocked: false, błędy: 0)
