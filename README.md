# Batelkův AI Chat

Jednoduchá ChatGPT-style webová aplikace postavená na čistém PHP a vanilla JS. Používá Google AI Studio API s modely Gemini a Gemma.

## Funkce

- Chat rozhraní s bublinami (uživatel vpravo, AI vlevo)
- Napojení na Google AI Studio API (Gemini 2.5, 3.x, Gemma 3/4 a další)
- Konfigurovatelné modely v `models.json` (enable/disable, výchozí model)
- Systémové instrukce pro AI v `system-prompt.txt`
- Session-based konverzace — každé okno/prohlížeč má vlastní chat
- Náhodná úvodní zpráva AI při nové session
- Historie konverzace uložená v `chat.json`
- Kontext celé konverzace se posílá do API
- Markdown formátování AI odpovědí (bold, italic, seznamy, kód, citace, odkazy)
- AJAX komunikace bez reloadu stránky
- Typing indikátor během čekání na odpověď
- Přepínání modelů (dropdown)
- Tlačítko pro smazání chatu
- Tmavý moderní design s glassmorphism efektem
- Responzivní layout
- SEO meta tagy a favicon

## Požadavky

- PHP 8.0+ s rozšířením `openssl`
- API klíč z [Google AI Studio](https://aistudio.google.com/apikey)

## Instalace

1. Klonuj repozitář:
   ```bash
   git clone https://github.com/UZIVATEL/aisek.git
   cd aisek
   ```

2. Vytvoř soubor `.env` s API klíčem (pro lokální vývoj):
   ```
   GOOGLE_AI_API_KEY=tvuj-api-klic
   ```

3. Spusť PHP server:
   ```bash
   php -S localhost:8000
   ```

4. Otevři `http://localhost:8000` v prohlížeči.

## Nasazení (Render / Docker)

Aplikace obsahuje `Dockerfile` pro nasazení na platformy jako Render:

1. Nastav environment variable `GOOGLE_AI_API_KEY` v dashboardu hostingu.
2. Platforma buildne Docker image a spustí Apache server.

`.env` soubor se na Git nepushuje (je v `.gitignore`).

## Konfigurace

| Soubor | Účel |
|---|---|
| `models.json` | Seznam modelů — `enabled`, `default` |
| `system-prompt.txt` | Systémové instrukce pro AI |
| `.env` | API klíč pro lokální vývoj (v `.gitignore`) |

## Struktura

```
index.php          – PHP backend + HTML + JS frontend
style.css          – styly aplikace
models.json        – konfigurace dostupných modelů
system-prompt.txt  – systémové instrukce pro AI
chat.json          – historie konverzace (generuje se automaticky)
.env               – lokální API klíč (v .gitignore)
Dockerfile         – Docker konfigurace pro nasazení
```

## Licence

MIT
