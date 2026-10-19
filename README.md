# 高科制作課題 高機能サイト内検索エンジン

## 概要
sitemap.xmlを登録し、ページ取得＆コンテンツをベクトル化。
通常のキーワード検索以上に柔軟なサイト内検索を提供します。

## 必要な環境

- Docker
- Docker Compose
- Git
- OpenAI API KEY（課金必要 List models -> Embeddingsの権限が必要）

## 起動手順

Docker と Docker Compose が使える状態にしてから進めます。(Docker Desktopをインストールするのがオススメです)

### 1.リポジトリを取得
```bash
git clone https://github.com/yu-takashina/project-miune.git
cd <プロジェクトディレクトリ>
```

### 2. 環境設定ファイルを作成

```bash
cp docker/.env.example docker/.env
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env
```

必要に応じて.envの設定を変更してください。


#### `backend/.env`

Laravelの設定ファイルです。以下は必ず設定してください。

```dotenv
OPENAI_API_KEY=事前に準備したOpenAI API KEY
```

`OPENAI_API_KEY` が無効だと、記事・検索ワードのベクトル化が行えません。

#### `frontend/.env`

検索画面の設定ファイルです。以下は必ず設定してください。（5. アプリケーションにアクセスにて説明）

```dotenv
API_KEY=
```

### 3. Dockerコンテナを起動

リポジトリのルートで実行します。

```bash
docker compose up -d --build
```

### 4. Laravelのセットアップ

```bash
docker compose exec -T backend composer install
docker compose exec -T backend php artisan key:generate
docker compose exec -T backend npm install
docker compose exec -T backend npm run build
docker compose exec -T backend php artisan migrate --seed --force --no-interaction
```

### 5. アプリケーションにアクセス

1. [管理画面](http://localhost:8051/)にログインする。（ ログイン情報 → user1@example.com / 123456789 ）
2. ナビゲーションの「APIキー」をクリックしAPIキーを発行する。
3. 表示されたキーを `frontend/.env` の `API_KEY` に書く。
4. ナビゲーションの「サイトマップ」をクリックし、検索対象ページを巡回させるためのsitemap.xmlを登録する。

   リナビスのsitemap.xmlは以下3つです。

   - <https://rinavis.com/sitemap.xml>
   - <https://rinavis.com/sentakunote/sitemap_index.xml>
   - <https://rinavis.com/blog/sitemap.xml>

   定期的にサイトマップ巡回＆コンテンツ取得＆ベクトル化が行われます。

   30分程度経過後に、再度アクセスしてサイトマップ巡回状況をお確かめください。

5. [フロントの検索画面](http://localhost:8015/)にアクセスし、クリーニングに関する悩みを検索する。


## 使用技術・AIと用途

| 技術 | 用途 |
| --- | --- |
| Docker Compose | デモ実行環境の構築 |
| PHP 8.5 / Apache | アプリケーションを動かすために必要 |
| Laravel 13 | 管理画面のフレームワーク |
| MariaDB 13 | アプリのデータ。Laravelからベクトル検索をスムーズに行うため、MySQLではなくMariaDBを採用 |
| fivefilters/readability.php | 取得した HTML から本文を抜く |
| Trafilatura 2.3(Phthon) | 上記と同様 |
| OpenAI text-embedding-3-small | サイトコンテンツ・検索キーワードをベクトル化 |

## 未実装部分
- 管理画面の権限制御（デモ版のため、1ユーザーのフル権限のみ）
- sitemap.xmlのグループ化（リナビス・ノムラクリーニングで検索結果を分けたい場合には実装が必要）
- コンテンツの段落分割（検索精度向上には段落分割が有効の可能性（要検討）、ただし段落分割するにはOpenAIの課金が発生する）
- http_status 301, 302, 404などの場合の検証（Google Search Consoleで代用できるので、そちらを利用しながらsitemap.xmlを整備）

## 注意点
- 検索キーワードをベクトル化するのにOpenAIのトークンを消費します。　APIレート制限を入れておりますが、常にレート制限の上限までアクセスされ続けた場合、24時間で2,100万トークン（約$0.5）、月間$15程度かかり続ける計算となります。
