<?php

class Database
{
    private static ?PDO $pdo = null;
    private static string $dbPath = '/var/www/html/data/midiaflow.db';

    public static function setPath(string $path): void
    {
        self::$dbPath = $path;
        self::$pdo = null;
    }

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $dir = dirname(self::$dbPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            self::$pdo = new PDO('sqlite:' . self::$dbPath, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::init();
        }

        return self::$pdo;
    }

    private static function init(): void
    {
        self::$pdo->exec("
            CREATE TABLE IF NOT EXISTS perfis (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                arroba TEXT NOT NULL UNIQUE,
                nome TEXT,
                criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS textos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                texto TEXT NOT NULL,
                fonte_url TEXT,
                perfil_id INTEGER DEFAULT 1,
                criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS fundos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                path_imagem TEXT NOT NULL,
                descricao TEXT,
                fonte_url TEXT,
                criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS sessoes (
                id TEXT PRIMARY KEY,
                chat_id TEXT NOT NULL,
                media_path TEXT,
                fonte_url TEXT,
                analise_json TEXT,
                status TEXT DEFAULT 'pendente',
                criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // Insere perfil padrao se nao existe
        $stmt = self::$pdo->prepare("SELECT COUNT(*) FROM perfis WHERE arroba = ?");
        $stmt->execute(['@instituto.haux']);
        if ($stmt->fetchColumn() == 0) {
            $stmt = self::$pdo->prepare("INSERT INTO perfis (arroba, nome) VALUES (?, ?)");
            $stmt->execute(['@instituto.haux', 'Instituto Haux']);
        }
    }

    // ── Sessoes ──

    public static function criarSessao(string $id, string $chatId, string $mediaPath, string $fonteUrl, array $analise = []): void
    {
        $stmt = self::get()->prepare("INSERT OR REPLACE INTO sessoes (id, chat_id, media_path, fonte_url, analise_json, status) VALUES (?, ?, ?, ?, ?, 'pendente')");
        $stmt->execute([$id, $chatId, $mediaPath, $fonteUrl, json_encode($analise)]);
    }

    public static function buscarSessao(string $id): ?array
    {
        $stmt = self::get()->prepare("SELECT * FROM sessoes WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row && $row['analise_json']) {
            $row['analise'] = json_decode($row['analise_json'], true);
        }
        return $row ?: null;
    }

    // ── Textos ──

    public static function salvarTexto(string $texto, string $fonteUrl = ''): int
    {
        $stmt = self::get()->prepare("INSERT INTO textos (texto, fonte_url) VALUES (?, ?)");
        $stmt->execute([$texto, $fonteUrl]);
        return (int) self::get()->lastInsertId();
    }

    public static function listarTextos(int $limit = 5, int $offset = 0): array
    {
        $stmt = self::get()->prepare("SELECT * FROM textos ORDER BY criado_em DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }

    public static function buscarTexto(int $id): ?array
    {
        $stmt = self::get()->prepare("SELECT * FROM textos WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function contarTextos(): int
    {
        return (int) self::get()->query("SELECT COUNT(*) FROM textos")->fetchColumn();
    }

    // ── Fundos ──

    public static function salvarFundo(string $pathImagem, string $descricao = '', string $fonteUrl = ''): int
    {
        $stmt = self::get()->prepare("INSERT INTO fundos (path_imagem, descricao, fonte_url) VALUES (?, ?, ?)");
        $stmt->execute([$pathImagem, $descricao, $fonteUrl]);
        return (int) self::get()->lastInsertId();
    }

    public static function listarFundos(int $limit = 5, int $offset = 0): array
    {
        $stmt = self::get()->prepare("SELECT * FROM fundos ORDER BY criado_em DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll();
    }

    public static function buscarFundo(int $id): ?array
    {
        $stmt = self::get()->prepare("SELECT * FROM fundos WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function contarFundos(): int
    {
        return (int) self::get()->query("SELECT COUNT(*) FROM fundos")->fetchColumn();
    }

    // ── Perfis ──

    public static function perfilPadrao(): array
    {
        $stmt = self::get()->prepare("SELECT * FROM perfis ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        return $stmt->fetch() ?: ['arroba' => '@instituto.haux', 'nome' => 'Instituto Haux'];
    }
}
