# Androidアプリ化（PWA / TWA）

kcaldav の WEBカレンダーを、Androidの「アプリ」にする2段構え。

## 段階1：PWA（アプリ不要・今すぐ）

WEBカレンダーは PWA 対応済み（`manifest.webmanifest` / `sw.js` / アイコンを配信）。
スマホのChromeで `https://<ドメイン>/cal/` を開き、メニューの
「アプリをインストール / ホーム画面に追加」で、**ホーム画面にアイコンが付き、
全画面のアプリとして開く**。ストアもインストールも不要。

## 段階2：TWA でGoogle Playに出す

PWAを **TWA（Trusted Web Activity）** でくるみ、Playに出す正規ルート。
このリポジトリには生成済みの一式がある。

### 作られているもの（この端末でビルド済み）
- `android/twa-manifest.json` … TWA設定（packageId `jp.exbridge.kcaldav`、
  表示名「Kurageカレンダー」、startUrl `/cal/`）
- `android/upload-keystore.jks` … 署名鍵（**gitに入れない・紛失厳禁**。
  パスワードは `.env` の `ANDROID_KEYSTORE_PASSWORD`）
- `outputs/kcaldav-1.0.0.aab` … **Playにアップロードする実体**
- `outputs/kcaldav-1.0.0.apk` … 署名済みAPK（Playを介さず直接インストールして試せる）
- `https://<ドメイン>/.well-known/assetlinks.json` … Digital Asset Links（URLバーを消す証明。
  現状は**アップロード鍵**のSHA256）

### 再ビルド
```bash
bash scripts/build_android.sh   # 版数を上げてAAB/APKを作り直す
```

### Playに公開する手順（Googleアカウント側の作業）
1. [Google Play Console](https://play.google.com/console) で開発者登録（**$25・本人確認**）
2. アプリを作成 → 名前「Kurageカレンダー」
3. `outputs/kcaldav-1.0.0.aab` をアップロード
4. **Play App Signing に登録**（新規アプリは実質必須）。登録後、Play Console の
   「アプリの整合性 → アプリ署名鍵証明書」に出る **SHA-256** を控える
5. その SHA-256 を `assetlinks.json` の `sha256_cert_fingerprints` に**追記**して再デプロイ
   （Playが配信時に署名し直すため。アップロード鍵と2つ並べてよい）。これをしないと
   公開版でURLバーが消えない
6. ストア掲載情報（説明・スクショ・プライバシー方針・データ安全性フォーム）を埋める
7. **クローズドテスト**：新規の個人アカウントは、本番公開の前に
   **20人×14日間のテスト**が必須（Googleのルール。数週間かかる）
8. 審査 → 本番公開

### 注意
- 中身は「Webカレンダーを全画面で開くだけ」。ログインはBasic認証なので、
  起動時に認証ダイアログが出る（個人利用は問題なし。他人にも配る製品にするなら、
  各自のサーバー/認証を入力できるログイン画面を Web 側に足すのが望ましい）
- `upload-keystore.jks` を失うと同じアプリを更新できなくなる。安全にバックアップする
