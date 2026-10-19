# miune

このリポジトリのルートは Docker の定義。`compose.yaml` と `docker/` はここにある。Laravel のバックエンドは `backend/` にある。一般ユーザー向けの画面は `frontend/` にある。

バックエンドの作業指示は `backend/AGENTS.md` を正とする。PHP、Composer、Artisan、Pint、Larastan、PHPUnit、npm、データの扱い、変更後の確認は、そのファイルに従う。

フロントの作業指示は `frontend/AGENTS.md` を正とする。画面、検索の呼び出し、変更後の確認は、そのファイルに従う。

`docker compose` は、このリポジトリのルートで実行する。

## コミット

頼まれたときだけコミットする。メッセージは `.cursorrules` に従う。`.env` と `docker/db/data` はコミットしない。

## 範囲

頼まれたことだけを変える。隣の整理や、頼まれていない依存の追加はしない。
