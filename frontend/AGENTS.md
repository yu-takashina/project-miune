# miune

一般ユーザーが検索する画面。別の PHP サーバーで、画面の PHP がバックエンドの検索 API を呼ぶ。

エージェントへの作業指示は、このファイルを正とする。

ユーザーへの説明と、このファイルの更新は日本語で書く。

## 実行

PHP はフロントのコンテナで実行する。`docker compose` はリポジトリのルートで実行する。

```bash
docker compose exec -T frontend php …
```

ブラウザは http://localhost:8015 。ドキュメントルートは `public/`。

検索は `public/search.php` が `.env` の `API_BASE` と `API_KEY` を読み、`API_BASE` の `/api/search` を呼ぶ。

## 変更したあと

画面を変えたら、ブラウザで http://localhost:8015 を確認する。

## コミット

頼まれたときだけコミットする。メッセージは `.cursorrules` に従う。`.env` と `docker/db/data` はコミットしない。

## 範囲

頼まれたことだけを変える。隣の整理や、頼まれていない依存の追加はしない。
