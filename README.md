# カンファレンスタイマー「TIME-PON」

## 概要
「TIME-PON」はカンファレンスやプレゼンテーションなどの場で、遠隔操作で講演者に残り時間を表示したり、カンペを出すための Web アプリケーションです。  

- タイマーを演台において、制御側はオペレータが操作するという、ビューとコントロールが完全に分離されたタイマーシステムです。
- スピーカービューはカウントダウンタイマーと、オペレーターからの「カンペ」を見ることができます。
- オペレータは、全体の時間や警告時間などを設定し、適時スピーカーに「カンペ」を送ることができます。

- PHP による単一ファイル・軽量構成 (`index.php` など) で動作。
- 複数ルームを同時に作成・管理が可能。各ルームごとに “演台”（発表用画面）と “オペレータ”（管理用画面）を持つ。
- 持ち時間・警告タイミング・色の遷移などをカスタマイズ可能。
- 発表中の“カンペ”（補助メモ）表示／非表示、時間の一時停止・リセット等、操作性を考慮したショートカット付き。
- JSON データ形式で各ルームの状態を `data/` ディレクトリに保存。ルーム削除の自動処理あり。
- 単に１つのルームだけでなく **複数のルームを同時に管理**するダッシュボードもあります。

このリポジトリには Web サーバーに配置する PHP ファイル（`index.php` 等）その他、設定やデータ保存用のディレクトリ構造、ショートカットキーなどのクライアント／サーバー間の挙動制御コードが含まれています。

---

## 設置方法
1. 本ファイルを Web サーバーの公開ディレクトリに配置してください（例: `/public_html/timepon.php` または `index.php` 等）。  
2. 同階層に `data/` が無い場合は自動作成されます。手動作成時は **0775 以上の書込権限** を付与してください。  
   - 直接アクセス対策として `data/` を直リンク不可にすることを推奨（例: `.htaccess` で `Deny from all`）。  
   - もしくは公開領域外に移し、`id_to_file()` のパスを変更してください。  
3. PHP 8 以上推奨。排他制御／ロック（flock）が使用できる環境を推奨。

---

## 使い方

- ルートURLにアクセスし、「オペレータ」または「演台」を選択。  
- 新規ルーム作成で6桁IDが発行されます。  
  - 管理URL: `?op=1&id=XXXXXX`  
  - 演台URL: `?id=XXXXXX` を発表者などに配布してください。  
- ダッシュボード画面（オペレータ画面）では、複数ルームの状態一覧が見られます。各ルームに対して、持ち時間・第1・第2警告の設定変更、タイマーのスタート／一時停止／リセットなどが可能です。  
- 時計設定（持ち時間・第1/第2警告）はルームIDと独立に保存・更新されます（同じIDのまま）。  
- 警告色の遷移：グレー → 黄色（第1警告） → 朱色（第2警告） → 赤（0秒以降はやわらか点滅）。

---

## ショートカットキー

- **SHIFT+SPACE** : スタート / 一時停止のトグル  
- **SHIFT+R**     : リセット  
- **SHIFT+K**     : カンペ送信  
- **SHIFT+C**     : カンペ消去  

---

## 注意事項

- **ルームの保存期間**: 最後の更新から 7 日で自動削除されます。  
- **管理キー**: 作成時に生成され、管理URL の `#k=` フラグメントに含まれます。管理URLは共有しないでください。  
  - 演台URL（`?id=XXXXXX`）のみ登壇者へ配布してください。  
  - adminKey は推測困難ですが再発行はできません。  
- **6桁 ID**: ID 自体は秘匿情報ではありません（推測可能）。「管理URL」を漏らさない運用が前提です。  
- **保存データ**:  
  - 持ち時間・警告設定・カンペ・ステージ状態・adminKey が `data/` 配下の JSON に保存されます。  
  - 個人情報や機微情報はカンペに入力しないでください。  
  - パーミッションの目安: `dir=0775`, `file=0664`（umask 007）。  
- **レート制限**: 作成 10 件／分、HB 300 件／分、書き込み 120 件／分（IP 単位）。  
  - `429`／エラー相当の応答が出たら、間隔を空けて再試行してください。  
- **免責**: MIT ライセンス・無保証です。本番利用前に各自の環境・要件に合わせて十分なテストを行ってください。  

---

## ライセンス

MIT License

Copyright (c) 2025 Tetsu Suzuki

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

