# MídiaFlow 🎯

Central de conteúdo com IA — você joga um link, ela entrega a imagem pronta para postar.

## O que é

MídiaFlow é um pipeline de criação de conteúdo para Instagram operado via Telegram.

Você cola um link de qualquer post do Instagram (ou outra rede) num grupo do Telegram. O sistema baixa a mídia automaticamente, analisa com IA, gera legenda e hashtags no estilo da sua marca, redimensiona para o formato que você escolher e devolve pronta para postar.

Sem abrir Canva. Sem editar manualmente. Sem perder tempo.

---

## Fluxo completo

```
Você cola um link no grupo do Telegram
        ↓
yt-dlp baixa a mídia (foto ou vídeo)
        ↓
Claude analisa e gera frase + legenda + hashtags
        ↓
Bot pergunta: Feed, Reel, Portrait ou Story?
        ↓
ImageProcessor faz crop + resize no formato escolhido
        ↓
Imagem processada volta no chat com legenda pronta
```

---

## Estrutura do projeto

```
midiaflow/
├── webhook/
│   └── telegram.php          ← Ponto de entrada — recebe updates do Telegram
├── core/
│   ├── TelegramBot.php       ← Wrapper da API do Telegram (send, download, callback)
│   ├── MediaDownloader.php   ← Download de mídia via yt-dlp + extração de frame
│   ├── ImageProcessor.php    ← Crop centralizado + resize com GD (sem distorcer)
│   └── ClaudeAI.php          ← Análise de imagem e geração de conteúdo via Claude API
├── config/
│   └── config.php            ← Configurações centralizadas (formatos, paths, tokens)
├── storage/
│   ├── uploads/              ← Mídias originais baixadas
│   ├── processed/            ← Imagens processadas prontas para postar
│   └── queue/                ← Fila de agendamento (Fase 3)
├── .env                      ← Credenciais (não commitar)
├── .env.example              ← Modelo de variáveis de ambiente
└── .gitignore
```

---

## Requisitos

- PHP 8.1+
- Extensão GD (`php -m | grep gd`)
- Extensão cURL (`php -m | grep curl`)
- yt-dlp instalado no servidor
- ffmpeg instalado no servidor (para extração de frame de vídeos)

---

## Instalação no VPS

### 1. Clone o repositório

```bash
git clone https://github.com/Wellingtoncamaleao/midiaflow
cd midiaflow
```

### 2. Configure as variáveis de ambiente

```bash
cp .env.example .env
nano .env
```

Preencha:

```
TELEGRAM_BOT_TOKEN=    ← token do @BotFather
TELEGRAM_GROUP_ID=     ← ID do grupo (número negativo, ex: -100123456789)
CLAUDE_API_KEY=        ← sua chave da Anthropic
YTDLP_BIN=            ← caminho do binário (padrão: /usr/local/bin/yt-dlp)
```

### 3. Instale o yt-dlp

```bash
curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
  -o /usr/local/bin/yt-dlp
chmod +x /usr/local/bin/yt-dlp

# Verifica
yt-dlp --version
```

### 4. Instale o ffmpeg (para vídeos)

```bash
apt-get install -y ffmpeg
```

### 5. Permissões das pastas de storage

```bash
chmod 755 storage/uploads storage/processed storage/queue
```

### 6. Configure o webhook do Telegram

```bash
curl -X POST "https://api.telegram.org/bot{SEU_TOKEN}/setWebhook" \
  -d "url=https://seudominio.com/webhook/telegram.php"
```

### 7. Adicione o bot ao grupo

- Crie o bot com @BotFather se ainda não tiver
- Adicione o bot ao seu grupo do Telegram
- Para pegar o ID do grupo: mande uma mensagem no grupo e acesse:
  `https://api.telegram.org/bot{TOKEN}/getUpdates`
  Procure por `"chat":{"id":-100xxxxxxxxx}`

---

## Como usar

1. Abra o grupo do Telegram configurado
2. Cole qualquer link de post do Instagram (ou Pinterest, TikTok, Twitter/X, YouTube)
3. Aguarde a análise da IA (~5 segundos)
4. Escolha o formato de saída:
   - **Feed** — 1080x1080 (quadrado)
   - **Reel** — 1080x1920 (vertical)
   - **Portrait** — 1080x1350 (retrato)
   - **Story** — 1080x1920 (vertical)
5. Receba a imagem processada com legenda e hashtags prontas

---

## Redes suportadas

| Rede | Fotos | Vídeos/Reels |
|------|-------|--------------|
| Instagram | ✅ | ✅ (extrai frame) |
| Pinterest | ✅ | ✅ |
| TikTok | ✅ | ✅ |
| Twitter / X | ✅ | ✅ |
| YouTube | — | ✅ (extrai frame) |

> Posts privados do Instagram não funcionam.

---

## Roadmap

| Fase | Funcionalidade | Status |
|------|---------------|--------|
| 1 | Link → IA analisa → imagem processada no formato certo | ✅ Pronto |
| 2 | Identidade visual aplicada automaticamente (logo, fonte, cor da marca) | 🔜 |
| 3 | Agendamento — aprovar no Telegram e entrar na fila de postagem | 🔜 |
| 4 | Múltiplas entradas — texto solto, áudio, ideia | 🔜 |
| 5 | Múltiplas saídas — Instagram, WhatsApp Channel, Stories simultâneos | 🔜 |
| 6 | Vídeo/Reels gerados com Remotion | 🔜 |
| 7 | Produto/SaaS — multi-tenant para agências e criadores | 🔜 |

---

## Variáveis de ambiente

| Variável | Descrição | Obrigatório |
|----------|-----------|-------------|
| `TELEGRAM_BOT_TOKEN` | Token do bot (@BotFather) | ✅ |
| `TELEGRAM_GROUP_ID` | ID do grupo de operação | ✅ |
| `CLAUDE_API_KEY` | Chave da API Anthropic | ✅ |
| `YTDLP_BIN` | Caminho do binário yt-dlp | ⬜ (default: yt-dlp) |
| `INSTAGRAM_ACCESS_TOKEN` | Token da Graph API (Fase 3) | ⬜ |
| `INSTAGRAM_ACCOUNT_ID` | ID da conta Instagram (Fase 3) | ⬜ |

---

## Stack

- **PHP 8.1+** — backend
- **GD** — processamento de imagem
- **yt-dlp** — download de mídia
- **ffmpeg** — extração de frame de vídeo
- **Claude API** — análise e geração de conteúdo
- **Telegram Bot API** — interface de operação
- **SQLite** — fila de agendamento (Fase 3)

---

## Licença

Privado — Wellington Camaleão / GestorConecta
