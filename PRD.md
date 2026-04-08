# MidiaFlow v2 — Pipeline de Reaproveitamento de Conteudo para Instagram

## Problema

Criadores de conteudo e agencias gastam horas reaproveitando posts de inspiracao do Instagram. O processo manual envolve: salvar a imagem, pensar numa frase similar, abrir Canva/Photoshop, montar o criativo com a identidade visual da marca, e postar. Isso se repete dezenas de vezes por semana.

O MidiaFlow v1 ja resolve parte do problema (baixar midia + gerar legenda), mas o usuario ainda precisa sair do Telegram pra criar o visual final. O reaproveitamento criativo — recriar a essencia de um post com a identidade da marca — ainda e 100% manual.

## Solucao

Transformar o MidiaFlow num pipeline completo de reaproveitamento de conteudo operado 100% via Telegram. O usuario cola um link, o bot baixa a imagem, e oferece acoes inteligentes:

- **Modelar**: IA analisa a imagem original, extrai a essencia (frase + estilo visual), e recria uma versao similar com DALL-E incluindo o @ do perfil do usuario. Manda pra aprovacao antes de finalizar.
- **Clonar Fundo**: IA remove o texto da imagem e salva o fundo limpo como template reutilizavel.
- **Guardar Texto**: IA extrai o texto/frase da imagem via OCR e salva no banco pra uso futuro.
- **Criar**: Combina um fundo ja salvo + uma frase ja salva pra gerar uma imagem nova com DALL-E. Nao precisa de link — usa o que ja tem no banco.

Arquitetura extensivel — novas acoes (Agendar Post, Gerar Carousel, Traduzir) podem ser adicionadas como novos botoes sem mudar a estrutura.

## Criterios de Aceite

### Fluxo Principal
- [ ] Usuario manda link no chat → bot baixa imagem via Cobalt e mostra no chat
- [ ] Bot pergunta "O que deseja fazer?" com botoes inline: [Modelar] [Clonar Fundo] [Guardar Texto]
- [ ] Comando /criar funciona independente (sem link) — combina fundo + frase do banco
- [ ] Cada botao leva pro fluxo correspondente

### Modelar
- [ ] IA analisa imagem original e extrai: frase, estilo visual, composicao
- [ ] DALL-E 3 gera imagem nova similar com a frase + @instituto.haux
- [ ] Bot envia imagem gerada pro chat com botoes [Aprovar] [Refazer]
- [ ] Refazer gera nova variacao
- [ ] Aprovar salva a imagem final no storage

### Clonar Fundo
- [ ] IA recria a imagem sem o texto (fundo limpo)
- [ ] Fundo salvo no SQLite (tabela fundos) + storage/fundos/
- [ ] Bot confirma: "Fundo salvo!"

### Guardar Texto
- [ ] IA extrai texto da imagem via GPT-4o vision (OCR)
- [ ] Texto salvo no SQLite (tabela textos) com URL de origem
- [ ] Bot confirma: "Texto salvo: '...'"

### Criar (/criar — independente de link)
- [ ] Bot manda "Escolha uma frase:" com 5 textos do banco como botoes inline
- [ ] Ultimo botao: [Outras opcoes →] carrega proximos 5
- [ ] Apos escolher frase, bot manda "Escolha um fundo:" com 5 fundos (envia como fotos com botao de selecao)
- [ ] Ultimo botao: [Outras opcoes →] carrega proximos 5
- [ ] DALL-E gera imagem combinando fundo escolhido + frase escolhida + @perfil
- [ ] Bot envia resultado com [Aprovar] [Refazer]

### Perfis
- [ ] Perfil @instituto.haux pre-cadastrado no SQLite
- [ ] Futuramente: comando /perfil pra cadastrar novos

### Persistencia
- [ ] SQLite no container com volume persistente
- [ ] Tabelas: perfis, textos, fundos

## Fora de Escopo

- Multiplos perfis simultaneos (v2 so instituto.haux, multi-perfil vem depois)
- Agendamento de postagem
- Publicacao direta no Instagram (Graph API)
- Geracao de carousel (multiplas imagens)
- Edicao interativa da imagem gerada (inpainting manual)
- Interface web/admin
- Traducao automatica

## User Stories

1. Como criador de conteudo, quero colar um link de post inspiracao e receber uma versao recriada com minha identidade visual, pra nao precisar abrir Canva.

2. Como criador de conteudo, quero extrair e salvar frases de posts que me inspiram, pra ter um banco de textos pra usar depois.

3. Como criador de conteudo, quero salvar fundos limpos (sem texto) de imagens bonitas, pra reutilizar como templates.

## Dados

### Tabelas novas (SQLite)

| Tabela | Colunas | Descricao |
|--------|---------|-----------|
| perfis | id, arroba, nome, criado_em | Perfis Instagram gerenciados |
| textos | id, texto, fonte_url, perfil_id, criado_em | Frases extraidas de posts |
| fundos | id, path_imagem, fonte_url, descricao, criado_em | Fundos limpos salvos |
| sessoes | id, chat_id, media_path, fonte_url, analise_json, status, criado_em | Sessao ativa de processamento |

### Tabelas modificadas
Nenhuma (banco novo SQLite).

## Requisitos Tecnicos

- **DALL-E 3**: API OpenAI Images, modelo dall-e-3, tamanho 1024x1024 (padrao)
- **Vision/OCR**: GPT-4o-mini via WellDev (image_url) ou OpenAI direto
- **Storage**: SQLite em volume Docker persistente (/var/www/html/data/midiaflow.db)
- **Custo por "Modelar"**: ~US$ 0.04 (1 geracao DALL-E 3)
- **Custo por "Clonar Fundo"**: ~US$ 0.04 (1 geracao DALL-E 3)
- **Custo por "Guardar Texto"**: ~US$ 0.001 (1 chamada GPT-4o-mini)
- **Timeout**: Geracao DALL-E pode levar 15-30s, bot deve avisar "Gerando..."

## Arquitetura

### Arquivos novos
```
core/Database.php        ← SQLite wrapper (init tables, queries)
core/DalleAI.php         ← Geracao de imagem via DALL-E 3 API
data/                    ← Pasta pro SQLite + fundos salvos
```

### Arquivos modificados
```
webhook/index.php        ← Fluxo novo com 3 botoes + handlers
core/ClaudeAI.php        ← Adicionar metodo de OCR (extrair texto)
Dockerfile               ← Extensao pdo_sqlite + volume /data
docker-compose.yml       ← Volume persistente pro /data
```

### Fluxo de Callbacks
```
link recebido
  → download via Cobalt
  → salva sessao no SQLite
  → mostra imagem + botoes [Modelar] [Clonar Fundo] [Guardar Texto]

callback "action:{sessionId}:modelar"
  → IA analisa imagem (descreve + extrai frase)
  → DALL-E gera imagem nova com frase + @perfil
  → mostra no chat + [Aprovar] [Refazer]

callback "action:{sessionId}:clonar_fundo"
  → DALL-E gera versao sem texto (ou inpainting)
  → salva no SQLite + storage
  → confirma no chat

callback "action:{sessionId}:guardar_texto"
  → GPT-4o extrai texto via vision
  → salva no SQLite
  → confirma no chat

callback "approve:{sessionId}" / "redo:{sessionId}"
  → Aprovar: salva imagem final
  → Refazer: gera nova variacao

comando /criar (sem link, direto do banco)
  → lista fundos salvos → usuario escolhe
  → lista textos salvos → usuario escolhe
  → DALL-E gera imagem com fundo + frase + @perfil
  → mostra no chat + [Aprovar] [Refazer]
```
