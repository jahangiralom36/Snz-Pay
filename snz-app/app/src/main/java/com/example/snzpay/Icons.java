package com.example.snzpay;

import android.graphics.Canvas;
import android.graphics.ColorFilter;
import android.graphics.Paint;
import android.graphics.Path;
import android.graphics.PixelFormat;
import android.graphics.RectF;
import android.graphics.drawable.Drawable;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

/**
 * অ্যাপের সব আইকন এই একটা ফাইলে (আলাদা drawable ফাইল লাগে না)।
 * প্রতিটা আইকন 24x24 এর SVG path — যেকোনো রঙে, যেকোনো সাইজে পরিষ্কার দেখায়।
 *
 * ফরম্যাট: "F:" = ভরাট, "E:" = ভরাট (ভেতরে ফাঁকা অংশসহ), "S:" = শুধু দাগ (outline); একাধিক অংশ ";" দিয়ে আলাদা
 */
public final class Icons {
    private Icons() {}

    private static final Map<String, String> SET = new HashMap<>();
    static {
        SET.put("send", "F:M2,21L23,12L2,3V10L17,12L2,14Z");
        SET.put("coins", "F:M4,6c0,-1.7 3.6,-3 8,-3s8,1.3 8,3s-3.6,3 -8,3s-8,-1.3 -8,-3z M4,9.5c1.6,1.3 4.6,2 8,2s6.4,-0.7 8,-2V12c0,1.7 -3.6,3 -8,3s-8,-1.3 -8,-3z M4,15c1.6,1.3 4.6,2 8,2s6.4,-0.7 8,-2v2.5c0,1.7 -3.6,3 -8,3s-8,-1.3 -8,-3z");
        SET.put("rocket", "E:M12,2C15.5,4.5 17,8.5 17,12.5L15.5,16H8.5L7,12.5C7,8.5 8.5,4.5 12,2Z M10.2,9a1.8,1.8 0 1,0 3.6,0a1.8,1.8 0 1,0 -3.6,0Z;F:M7,13.5L4,16.5V20L8,17.5Z M17,13.5L20,16.5V20L16,17.5Z M10,17.5H14L12,22Z");
        SET.put("mobile", "E:M7,1.5H17A2,2 0 0,1 19,3.5V20.5A2,2 0 0,1 17,22.5H7A2,2 0 0,1 5,20.5V3.5A2,2 0 0,1 7,1.5Z M7,4.5V17.5H17V4.5Z M11,19H13V21H11Z");
        SET.put("bank", "F:M12,1.5L2,7V9H22V7Z M4,10.5H7V17.5H4Z M10.5,10.5H13.5V17.5H10.5Z M17,10.5H20V17.5H17Z M2,19H22V22H2Z");
        SET.put("tap", "E:M3,12a9,9 0 1,0 18,0a9,9 0 1,0 -18,0Z M5,12a7,7 0 1,0 14,0a7,7 0 1,0 -14,0Z M9,12a3,3 0 1,0 6,0a3,3 0 1,0 -6,0Z");
        SET.put("wallet", "E:M4,5H18V7H4.5A0.5,0.5 0 0,0 4.5,8H20A2,2 0 0,1 22,10V19A2,2 0 0,1 20,21H4A2,2 0 0,1 2,19V7A2,2 0 0,1 4,5Z M15.5,14.5a1.5,1.5 0 1,0 3,0a1.5,1.5 0 1,0 -3,0Z");
        SET.put("card", "E:M4,4H20A2,2 0 0,1 22,6V18A2,2 0 0,1 20,20H4A2,2 0 0,1 2,18V6A2,2 0 0,1 4,4Z M2,8V11H22V8Z M5,15H10V17H5Z");
        SET.put("qr", "E:M3,3H10V10H3Z M5,5V8H8V5Z M14,3H21V10H14Z M16,5V8H19V5Z M3,14H10V21H3Z M5,16V19H8V16Z;F:M14,14H16V16H14Z M16,16H18V18H16Z M18,14H21V16H18Z M14,18H16V21H14Z M18,18H21V21H18Z");
        SET.put("menu", "F:M3,5.5H21V8H3Z M3,10.75H21V13.25H3Z M3,16H21V18.5H3Z");
        SET.put("history", "S:M4,12A8,8 0 1,0 6.4,6.3 M3,4V8.5H7.5 M12,7.5V12L15,14");
        SET.put("calendar", "S:M4.5,5H19.5V20.5H4.5Z M4.5,9.5H19.5 M8.5,3V6.5 M15.5,3V6.5 M8.8,15L11,17.2L15.5,12.7");
        SET.put("logout", "S:M10,4H5.5V20H10 M11,12H21 M17,8L21,12L17,16");
        SET.put("shield", "E:M12,2L4,5V11C4,16 7.4,20.7 12,22C16.6,20.7 20,16 20,11V5Z M10.5,15.5L7.4,12.4L8.8,11L10.5,12.7L15.2,8L16.6,9.4Z");
        SET.put("user", "F:M12,3a4.2,4.2 0 1,0 0.01,0Z M4,21C4,16.6 7.6,14 12,14S20,16.6 20,21Z");
        SET.put("back", "S:M15,5L8,12L15,19");
    }

    /** নাম দিয়ে আইকন (না থাকলে wallet) */
    public static Drawable get(String name, int color) {
        String spec = SET.get(name);
        return new IconDrawable(spec != null ? spec : SET.get("wallet"), color);
    }

    // ---------------------------------------------------------------------

    private static final class Part {
        Path path;
        boolean stroke;
    }

    private static final class IconDrawable extends Drawable {
        private final List<Part> parts = new ArrayList<>();
        private final Paint fill = new Paint(Paint.ANTI_ALIAS_FLAG);
        private final Paint line = new Paint(Paint.ANTI_ALIAS_FLAG);

        IconDrawable(String spec, int color) {
            fill.setStyle(Paint.Style.FILL);
            fill.setColor(color);
            line.setStyle(Paint.Style.STROKE);
            line.setColor(color);
            line.setStrokeWidth(2f);
            line.setStrokeCap(Paint.Cap.ROUND);
            line.setStrokeJoin(Paint.Join.ROUND);
            for (String seg : spec.split(";")) {
                if (seg.length() < 3) continue;
                char k = seg.charAt(0);
                PathSink sink = new PathSink();
                SvgPath.parse(seg.substring(2), sink);
                Part p = new Part();
                p.path = sink.path;
                p.path.setFillType(k == 'E' ? Path.FillType.EVEN_ODD : Path.FillType.WINDING);
                p.stroke = k == 'S';
                parts.add(p);
            }
        }

        @Override
        public void draw(Canvas c) {
            android.graphics.Rect b = getBounds();
            float s = Math.min(b.width(), b.height()) / 24f;
            int save = c.save();
            c.translate(b.left + (b.width() - 24 * s) / 2f, b.top + (b.height() - 24 * s) / 2f);
            c.scale(s, s);
            for (Part p : parts) c.drawPath(p.path, p.stroke ? line : fill);
            c.restoreToCount(save);
        }

        @Override public int getIntrinsicWidth() { return 96; }
        @Override public int getIntrinsicHeight() { return 96; }
        @Override public void setAlpha(int a) { fill.setAlpha(a); line.setAlpha(a); invalidateSelf(); }
        @Override public void setColorFilter(ColorFilter cf) { fill.setColorFilter(cf); line.setColorFilter(cf); invalidateSelf(); }
        @Override public int getOpacity() { return PixelFormat.TRANSLUCENT; }
    }

    /** android Path এ আঁকা */
    private static final class PathSink implements SvgPath.Sink {
        final Path path = new Path();
        public void moveTo(float x, float y) { path.moveTo(x, y); }
        public void lineTo(float x, float y) { path.lineTo(x, y); }
        public void cubicTo(float a, float b, float c, float d, float e, float f) { path.cubicTo(a, b, c, d, e, f); }
        public void quadTo(float a, float b, float c, float d) { path.quadTo(a, b, c, d); }
        public void arc(float cx, float cy, float rx, float ry, float startDeg, float sweepDeg) {
            path.arcTo(new RectF(cx - rx, cy - ry, cx + rx, cy + ry), startDeg, sweepDeg, false);
        }
        public void close() { path.close(); }
    }
}
