# Dropage

WordPress の管理画面から静的WebページをZIPで公開・削除するプラグインです。キャンペーンの際などに一時的に公開するページを簡単にアップロードすることが目的のプラグインです。

> アップロードするZIPの内容まではチェックしていませんのでZIP化する前に安全性の確認を確実に行ってからアップロードするようにしてください。

## 導入

1. このディレクトリを `wp-content/plugins/dropage` に配置し、管理画面の「プラグイン」で Dropage を有効化します。
2. PHP 8.2 以降と PHP の `zip` 拡張を用意します。単一サイト構成で、管理画面を使うアカウントには `manage_options` と `unfiltered_html` が必要です。
3. サイトのホームURLに対応する公開ディレクトリに `extras` を作成し、PHP プロセスに書き込み権限を与えます。親ディレクトリに書き込める場合は初回公開時に自動作成されます。
4. Webサーバーで `/extras/` を静的ファイルとして配信し、PHP・CGI・SSI の実行、ディレクトリ一覧、およびドットで始まるパスへのHTTPアクセスを無効にします。**公開ページのHTML・JavaScriptはWordPressと同じオリジンで動作するため、内容を信頼できるZIPだけを公開してください**。

### Apache のリライトルール

Apache 2.4 の `mod_rewrite` を使用している場合は、サイトの公開ディレクトリの `.htaccess` に以下を追加します。WordPress が生成する `# BEGIN WordPress` ブロックより前に置きます。静的配信の設定はWebサーバー側で行ってください。

```apache
RewriteEngine On
RewriteRule ^extras/(?:[^/]+/)*\. - [F]
RewriteRule ^extras(?:/|$) - [END]
```

[Apache のリライトルールとフラグ](https://httpd.apache.org/docs/current/en/rewrite/flags.html)

## 使い方

1. 管理画面の「ツール → Dropage」を開きます。
2. HTMLと、そこから相対パスで参照するCSS、JavaScript、画像などをZIPにまとめます。`campaign.zip` を公開すると、ZIP内の構造を保ったまま `/extras/campaign/` に配置します。
3. ZIPをドロップすると公開します。ファイル選択欄を使う場合は、選択後に「公開する」を押します。
4. 公開済みページの名前をクリックすると、最も浅い階層のHTMLが開きます。同じ階層に `index.html` または `index.htm` があれば優先します。「削除」で公開ディレクトリごと削除できます。

一覧と削除は Dropage が公開したディレクトリが対象です。同じ名前の公開先がある場合は、ZIP名を変更するか、既存ページを削除してから公開します。

ZIPは50 MiBまで、展開後の合計は200 MiBまで、ファイルとフォルダーは合計5,000件までです。PHP やWebサーバーのアップロード上限が小さい場合は、その上限が適用されます。

## ライセンス

MIT

## 補足事項

- このプラグインはCodexに相談しながら作成しました。
