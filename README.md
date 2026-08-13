# sonoTracks Discography（WordPress プラグイン）

sonoTracks で販売している作品の一覧を、**アーティストご自身の WordPress サイト**に表示します。

作品を追加したり、公開・非公開を切り替えたりすると、**10分以内に自動で反映されます。**
サイト側で作品を登録し直す必要はありません。

## 入れかた

1. 配布された `sonotracks-discography.zip` をダウンロードする
2. WordPress の管理画面で **プラグイン → 新規追加 → プラグインのアップロード**
3. zip を選んでインストールし、**有効化**する
4. **設定 → sonoTracks** を開き、sonoTracks のプロフィールページのURL
   （`https://sono-tracks.com/u/○○○`）を貼り付けて保存する
   - URL をまるごと貼っても、末尾の ID だけを入れてもかまいません
   - 保存すると、その場で「◯件の作品が見つかりました」と出ます

## 使いかた

投稿や固定ページに、次のショートコードを書きます。

```
[sonotracks_discography]
```

1ページの件数や列数を変えたいとき（件数は最大24）:

```
[sonotracks_discography limit="8" columns="3"]
```

**すべての作品を見てもらいたいとき**（ページ送りが付きます）:

```
[sonotracks_discography paged="true"]
```

ページ送りは、1つのページに1つの一覧でお使いください。同じページに2つ置くと、
どちらの操作でも両方が動いてしまいます。

1ページに出せるのは最大24件です。それ以上を一度に出さないのは、作品の多い方の
ページで表示が重くなるためです。ページ送りを付ければ、何件あっても全部たどれます。

別の方の作品を出したいとき（レーベルのサイトなど）:

```
[sonotracks_discography slug="○○○"]
```

## 更新について

新しい版が出ると、**WordPress の管理画面に「更新があります」と出ます。** そのまま
ワンクリックで更新できます（wordpress.org は経由せず、sonoTracks から直接届きます）。

⚠️ **v1.1.0 以前をお使いの方には、このお知らせが届きません。**
お知らせの仕組みが v1.2.0 から入ったためです。お手数ですが、
<https://sono-tracks.com/wordpress> から新しい zip を落として、一度だけ入れ直して
ください。以後は自動でお知らせします。

## 仕組み

WordPress が sonoTracks の公開APIを**読みに行きます**（pull型）。sonoTracks からお使いのサイトへ
書き込むことはありません。

- 取得は**サーバー側**で行い、10分だけキャッシュします。訪問者のブラウザから
  sonoTracks を直接叩くことはしません
- sonoTracks が一時的に落ちているときは、**最後に表示できていた内容**をそのまま出します。
  それも無ければ何も表示しません（エラー文がサイトに出ることはありません）
- 作品を非公開にした場合は、**落ちているときでも復活しません**（0件だと確かめられた
  時点で控えも消します）
- 設定 → sonoTracks の「表示を今すぐ更新する」で、10分を待たずに反映できます
  （`slug="○○○"` と直接指定している一覧や、ページ送りの各ページも含めて全部が対象です）
- 取得するのは公開情報だけです（作品名・アーティスト名・ジャンル・曲数・最低価格・
  ジャケット画像・作品ページのURL）。購入者の情報や試聴音源は取得しません

## 見た目を変えたいとき

色や文字は指定していないので、お使いのテーマの見た目をそのまま引き継ぎます。

### 設定画面から変える（CSS を書かなくてよい方法）

**設定 → sonoTracks** の「見た目」で、間隔・角丸・縦横比・色などを入力できます。
空欄のままにした項目は、テーマの見た目をそのまま引き継ぎます。色は「この色を指定する」に
印を付けたものだけが使われます。

保存すると、次のような指定が出ます（本文にショートコードを書いた場合は `<head>` に、
ウィジェット等に置いた場合はページの終わりに出ます）。

```html
<style>.sonotracks-dg{--sonotracks-dg-gap:24px;--sonotracks-dg-radius:10px}</style>
```

### CSS で変える

子テーマの `style.css` などに、次のように書いてください（設定画面より優先したい場合は、
詳細度を上げるか `!important` を使ってください）。

```css
.sonotracks-dg {
  --sonotracks-dg-gap: 24px;
  --sonotracks-dg-radius: 10px;
  --sonotracks-dg-title-color: #111;
  --sonotracks-dg-artist-color: #777;
  --sonotracks-dg-artist-opacity: 1;
}
```

一覧ごとに変えたいときは、その一覧を囲む要素に書けば、その中だけに効きます。

| 名前 | 既定値 | 何に効くか |
|---|---|---|
| `--sonotracks-dg-columns` | `4` | 列数（ショートコードの `columns` から渡ります） |
| `--sonotracks-dg-min` | `140px` | 1枠の最小幅。これを下回ると折り返します |
| `--sonotracks-dg-gap` | `16px` | 作品どうしの間隔 |
| `--sonotracks-dg-radius` | `4px` | ジャケットの角丸 |
| `--sonotracks-dg-ratio` | `1 / 1` | ジャケットの縦横比 |
| `--sonotracks-dg-link-color` | `inherit` | 作品カード全体の文字色 |
| `--sonotracks-dg-title-color` | `inherit` | 作品名の色 |
| `--sonotracks-dg-title-weight` | `bold` | 作品名の太さ |
| `--sonotracks-dg-title-size` | `1em` | 作品名の大きさ |
| `--sonotracks-dg-artist-color` | `inherit` | アーティスト名の色 |
| `--sonotracks-dg-artist-opacity` | `0.8` | アーティスト名の薄さ |
| `--sonotracks-dg-price-color` | `inherit` | 価格の色 |
| `--sonotracks-dg-meta-size` | `0.9em` | アーティスト名と価格の大きさ |
| `--sonotracks-dg-pager-color` | `inherit` | ページ送りの文字色 |
| `--sonotracks-dg-pager-current` | `currentColor` | 現在ページの下線の色 |

アーティスト名は、既定では色を指定せず**薄さ**で本文との差を付けています。
色を指定する場合は `--sonotracks-dg-artist-opacity: 1` にして、色そのもので
差を付けるほうが読みやすくなります（薄い文字は、背景との明暗差が下がります）。

これで足りない場合は、次のクラスに直接 CSS を当ててください。

| クラス | 対象 |
|---|---|
| `.sonotracks-dg` | 全体 |
| `.sonotracks-dg__list` | 一覧（グリッド） |
| `.sonotracks-dg__item` | 1作品 |
| `.sonotracks-dg__artwork` | ジャケット画像 |
| `.sonotracks-dg__title` | 作品名 |
| `.sonotracks-dg__artist` | アーティスト名 |
| `.sonotracks-dg__price` | 価格 |
| `.sonotracks-dg__pager` | ページ送り |
| `.sonotracks-dg__more` | 「sonoTracks ですべて見る」 |

## 開発者向け

- API: `GET https://sono-tracks.com/api/tracks/public/artist-releases?slug={slug}&limit={n}&page={p}`
  （`total` / `totalPages` / `page` も返ります。範囲外のページは最後のページに丸められます）
  （sonoloop リポジトリ `app/api/tracks/public/artist-releases/route.ts`）
- 叩き先を変えたい場合は `wp-config.php` などで
  `define('SONOTRACKS_API_ORIGIN', 'https://...')` を定義してください
- 公開リポジトリ: <https://github.com/miryna1978/sonotracks-discography>
  （配布と履歴の鏡。**開発の本体は sonoTracks 側**で、リリースのたびに
  `./scripts/publish-wp-plugin.sh` が上書きします）
- 設計の背景は `docs/sonotracks-spec.md` 第9.4章（pull型を選んだ理由）
- 音楽メディア（media.sono-music.com）側は、同じAPIを `?wp_user_id=` で読んでいます
  （テーマの `inc/setup-sonotracks-new-releases.php`）。こちらのプラグインとは別物です

## ライセンス

GPLv2 or later
