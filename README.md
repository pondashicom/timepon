# カンファレンスタイマー「TIME-PON」

## 設置方法
1. 本ファイルを Web サーバーの公開ディレクトリに配置してください（例: `/public_html/timepon.php`）。
2. 同階層に `data/` が無い場合は自動作成されます。手動作成時は **0775 以上の書込権限**を付与してください。  
   - 直接アクセス対策として `data/` を直リンク不可にすることを推奨（例: `.htaccess` で `Deny from all`）。  
   - もしくは公開領域外に移し、`id_to_file()` のパスを変更してください。
3. PHP 8 以上推奨。排他制御/ロック（flock）が使用できる環境を推奨。

## 使い方
- ルートURLにアクセスし、「オペレータ」または「演台」を選択。
- 新規ルーム作成で6桁IDが発行されます。  
  - 管理URL: `?op=1&id=XXXXXX`  
  - 演台URL: `?id=XXXXXX` を配布してください。
- 時計設定（持ち時間・第1/第2警告）はルームIDと独立に保存・更新されます（同じIDのまま）。
- 警告色の遷移：**青 → 緑（第1警告） → オレンジ（第2警告） → 赤（0秒以降はやわらか点滅）**

## ショートカットキー
- **SHIFT+SPACE** : スタート / 一時停止のトグル  
- **SHIFT+R**     : リセット  
- **SHIFT+K**     : カンペ送信  
- **SHIFT+C**     : カンペ消去  

## 注意事項
- **ルームの保存期間**: 最後の更新から7日で自動削除されます。  
- **管理キー**: 作成時に生成され、管理URLの `#k=` フラグメントに含まれます。管理URLは共有しないでください。  
  - 演台URL（`?id=XXXXXX`）のみ登壇者へ配布してください。  
  - adminKey は推測困難ですが再発行はできません。  
- **6桁ID**: ID 自体は秘匿情報ではありません（推測可能）。「管理URL」を漏らさない運用が前提です。  
- **保存データ**: 持ち時間・警告設定・カンペ・ステージ状態・adminKey が `data/` 配下の JSON に保存されます。  
  - 個人情報や機微情報はカンペに入力しないでください。  
  - パーミッションの目安: `dir=0775`, `file=0664`（umask 007）。  
- **レート制限**: 作成 10件/分、HB 300件/分、書き込み 120件/分（IP単位）。  
  - `429`/エラー相当の応答が出たら、間隔を空けて再試行してください。  
- **免責**: MITライセンス・無保証です。本番利用前に各自の環境・要件に合わせて十分なテストを行ってください。  

## ライセンス
MIT License
Copyright (c) 2025 pondashi.com

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
