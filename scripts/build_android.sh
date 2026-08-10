#!/usr/bin/env bash
# TWA(Android)のAAB/APKを作り直す。要: bubblewrap導入済み・android/upload-keystore.jks・
# .env に ANDROID_KEYSTORE_PASSWORD。JDK17/Android SDK は ~/.bubblewrap を使う。
set -euo pipefail
cd "$(dirname "$0")/../android"
set -a; . ../.env; set +a
export JAVA_HOME="$HOME/.bubblewrap/jdk/$(ls "$HOME/.bubblewrap/jdk" | head -1)"
export ANDROID_HOME="$HOME/.bubblewrap/android_sdk"
export PATH="$JAVA_HOME/bin:$PATH"

# 版数を1つ上げる
python3 - <<'PY'
import json
m=json.load(open("twa-manifest.json"))
m["appVersionCode"]=int(m.get("appVersionCode",1))+1
json.dump(m,open("twa-manifest.json","w"),ensure_ascii=False,indent=2)
print("versionCode ->", m["appVersionCode"])
PY
VC=$(python3 -c "import json;print(json.load(open('twa-manifest.json'))['appVersionCode'])")
VN=$(python3 -c "import json;print(json.load(open('twa-manifest.json'))['appVersionName'])")
sed -i "s/versionCode [0-9]*/versionCode $VC/; s/versionName \"[^\"]*\"/versionName \"$VN\"/" app/build.gradle

./gradlew bundleRelease assembleRelease --no-daemon
BT="$(ls -d "$ANDROID_HOME"/build-tools/* | sort -V | tail -1)"
cp app/build/outputs/bundle/release/app-release.aab "../outputs/kcaldav-$VN.aab"
jarsigner -keystore upload-keystore.jks -storepass "$ANDROID_KEYSTORE_PASSWORD" -keypass "$ANDROID_KEYSTORE_PASSWORD" \
  -sigalg SHA256withRSA -digestalg SHA-256 "../outputs/kcaldav-$VN.aab" kcaldav
"$BT/zipalign" -f 4 app/build/outputs/apk/release/app-release-unsigned.apk aligned.apk
"$BT/apksigner" sign --ks upload-keystore.jks --ks-pass "pass:$ANDROID_KEYSTORE_PASSWORD" \
  --key-pass "pass:$ANDROID_KEYSTORE_PASSWORD" --out "../outputs/kcaldav-$VN.apk" aligned.apk
rm -f aligned.apk
echo "built: outputs/kcaldav-$VN.aab / outputs/kcaldav-$VN.apk"
