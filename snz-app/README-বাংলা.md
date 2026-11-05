# SNZ App (নতুন, সহজ ও হালকা)

## অ্যাপ যা করে — শুধু এটুকুই
1. **লগইন:** লাইসেন্স কী দিয়ে। সার্ভারের ডাটাবেজে চেক হয় — কী আছে কিনা, ব্যান কিনা, মেয়াদ।
   ফোনের নাম নিজে থেকে পড়ে নেয়। **এক কী = এক ফোন** (একই ফোনে logout করে আবার login করা যায়)।
2. **SMS:** ওয়েবসাইটের Wallet Setup এ ইউজার যে wallet **ON** রেখেছে শুধু সেই টাকা-আসার SMS পড়ে → সার্ভারে পাঠায়।
   নেট না থাকলে ফোনে জমা থাকে, নেট আসলে নিজে নিজে যায়।
3. **মেয়াদ শেষ / ব্যান / ডিলিট** → অ্যাপ থেকে বের করে দেয়। নেট/সার্ভারের সমস্যায় কখনো বের করে না।

## যা নেই (তাই ফোন ভারী হয় না)
সারাক্ষণ চলা সার্ভিস, নোটিফিকেশন, blur, animation, লোগো ডাউনলোড, অটো-রিস্টার্ট — কিছুই নেই।
নতুন SMS এলে Android নিজেই অ্যাপ জাগায়; বাকি কাজ Android এর JobScheduler (প্রতি ১৫ মিনিট / নেট আসলে)।

## ফাইল
| ফাইল | কাজ |
|---|---|
| Config.java | ওয়েবসাইটের ঠিকানা, token, master key |
| Api.java | সার্ভারে পাঠানো (ফায়ারওয়াল-নিরাপদ) |
| Session.java | লগইন তথ্য, মেয়াদ, কোন wallet ON |
| KeyCheck.java | কী যাচাই / লগআউট |
| SmsParser.java | SMS থেকে TrxID, টাকা, নম্বর |
| Db.java | SQLite ডাটাবেজ `snz_app.db` — SMS History (`sms` টেবিল) + অ্যাপের সব সেটিং/লগইন (`kv` টেবিল) |
| Sync.java | SMS সেভ + সার্ভারে পাঠানো + মিস হওয়া SMS খোঁজা |
| SmsReceiver.java | নতুন SMS এলে |
| SyncJob.java | প্রতি ১৫ মিনিট / নেট আসলে |
| BootReceiver.java | ফোন চালু হলে |
| LoginActivity.java / MainActivity.java | লগইন, মূল স্ক্রিন (☰ মেনু + All History একই স্ক্রিনে) |
| CrashLog.java | হঠাৎ বন্ধ হলে কারণ সেভ করে, পরেরবার দেখায় (Copy) |
| Ui.java | স্টাইল, পপআপ, মেনু, স্ট্যাটাস বার ঠিক রাখা |

## 🖼️ নিজের লোগো দেওয়া (খুব সহজ)
1. `app/src/main/res/drawable/app_logo.xml` ফাইলটা ডিলিট করুন
2. নিজের লোগো PNG **`app_logo.png`** নামে `res/drawable/` ফোল্ডারে রাখুন (512×512, 200KB এর কম)
3. Build দিন — অ্যাপের ভেতরের লোগো আর ফোনের হোম স্ক্রিনের আইকন দুটোই বদলে যাবে।

## IDE এর টেমপ্লেট ফাইল
নতুন প্রজেক্টে IDE নিজে যে ফাইল দেয় (`values-night/themes.xml`, `drawable/ic_launcher_*.xml`, `mipmap/ic_launcher*.xml`)
— এই zip এর ফাইল দিয়ে **replace** করুন। এগুলোর দরকারি রঙও `colors.xml` এ আছে, তাই রেখে দিলেও error আসবে না।

## Build
- Namespace / Application ID: `com.example.snzpay` · Min SDK 26 · Target SDK 34 · Java 8
- প্রতিবার নতুন APK এর আগে **Version Code ১ করে বাড়ান** (এখন 30)।
- পুরনো অ্যাপ থাকলে আগে **uninstall** করে তারপর এটা ইনস্টল করুন।
- ইনস্টলের পর: Settings → Apps → SNZ Pay → ⋮ → Allow restricted settings → Permissions → SMS → Allow।
