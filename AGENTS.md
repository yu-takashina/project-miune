# miune

一般ユーザーが、雑多なブログ記事をベクトル検索で探しやすくするためのフロントのデモ。

最優先は、ユーザーが検索する画面とその操作。ブログの登録と、記事をベクトルにして保存する処理は作る。優先度は検索より低い。

Laravel 13。エージェントへの作業指示は、このファイルを正とする。

ユーザーへの説明と、このファイルの更新は日本語で書く。

## 実行

PHP、Composer、Artisan、Pint、Larastan、PHPUnit、npm は、アプリコンテナで実行する。

```bash
docker compose exec -T app php artisan …
docker compose exec -T app vendor/bin/pint
docker compose exec -T app vendor/bin/phpstan analyse --no-progress
docker compose exec -T app php artisan test
docker compose exec -T app npm run build
```

ブラウザは http://localhost:8051 。データベースは MariaDB（コンテナ `miune_db`、データベース名 `miune`）。

## 変更したあと

`app/`、`routes/`、`database/`、`config/`、`resources/views/` の PHP を変えたら、次の順で通し、エラー 0 件で終える。

1. Pint
2. Larastan（`phpstan.neon` の level 10。設定は緩めない）

テストを追加・変更したら PHPUnit も実行する。PHPUnit は `phpunit.xml` の sqlite メモリを使う。

`resources/` の CSS または JavaScript を変えたら `npm run build` を実行し、`public/build` を更新する。

## データ

アプリの MariaDB には、検証のために行を追加しない。`db:seed`、tinker の insert、確認用の登録リクエストをしない。

スキーマを変えたときだけ migrate する。`--seed` は付けない。

```bash
docker compose exec -T app php artisan migrate --force --no-interaction
```

## コミット

頼まれたときだけコミットする。メッセージは `.cursorrules` に従う。`.env` と `docker/db/data` はコミットしない。

## 範囲

頼まれたことだけを変える。隣の整理や、頼まれていない依存の追加はしない。
