package com.example.snzpay;

import java.util.ArrayList;
import java.util.List;

/** ছোট SVG path পার্সার (M L H V C S Q Z A, বড়/ছোট হাতের) — Icons এর জন্য */
final class SvgPath {
    private SvgPath() {}

    interface Sink {
        void moveTo(float x, float y);
        void lineTo(float x, float y);
        void cubicTo(float x1, float y1, float x2, float y2, float x, float y);
        void quadTo(float x1, float y1, float x, float y);
        void arc(float cx, float cy, float rx, float ry, float startDeg, float sweepDeg);
        void close();
    }

    static void parse(String d, Sink s) {
        List<Object> tk = tokens(d);
        int i = 0;
        char cmd = 'M';
        float x = 0, y = 0, sx = 0, sy = 0, lcx = 0, lcy = 0;
        char prev = ' ';
        while (i < tk.size()) {
            Object t = tk.get(i);
            if (t instanceof Character) { cmd = (Character) t; i++; if (cmd == 'Z' || cmd == 'z') { s.close(); x = sx; y = sy; prev = cmd; continue; } }
            boolean rel = Character.isLowerCase(cmd);
            char C = Character.toUpperCase(cmd);
            float ox = rel ? x : 0, oy = rel ? y : 0;
            switch (C) {
                case 'M': {
                    x = f(tk, i++) + ox; y = f(tk, i++) + oy; sx = x; sy = y; s.moveTo(x, y);
                    cmd = rel ? 'l' : 'L';   // পরের সংখ্যাগুলো lineTo
                    break;
                }
                case 'L': x = f(tk, i++) + ox; y = f(tk, i++) + oy; s.lineTo(x, y); break;
                case 'H': x = f(tk, i++) + (rel ? x : 0); s.lineTo(x, y); break;
                case 'V': y = f(tk, i++) + (rel ? y : 0); s.lineTo(x, y); break;
                case 'C': {
                    float x1 = f(tk, i++) + ox, y1 = f(tk, i++) + oy, x2 = f(tk, i++) + ox, y2 = f(tk, i++) + oy;
                    x = f(tk, i++) + ox; y = f(tk, i++) + oy;
                    s.cubicTo(x1, y1, x2, y2, x, y); lcx = x2; lcy = y2; break;
                }
                case 'S': {
                    char pc = Character.toUpperCase(prev);
                    float x1 = (pc == 'C' || pc == 'S') ? 2 * x - lcx : x, y1 = (pc == 'C' || pc == 'S') ? 2 * y - lcy : y;
                    float x2 = f(tk, i++) + ox, y2 = f(tk, i++) + oy;
                    x = f(tk, i++) + ox; y = f(tk, i++) + oy;
                    s.cubicTo(x1, y1, x2, y2, x, y); lcx = x2; lcy = y2; break;
                }
                case 'Q': {
                    float x1 = f(tk, i++) + ox, y1 = f(tk, i++) + oy;
                    x = f(tk, i++) + ox; y = f(tk, i++) + oy;
                    s.quadTo(x1, y1, x, y); break;
                }
                case 'A': {
                    float rx = f(tk, i++), ry = f(tk, i++), rot = f(tk, i++);
                    boolean large = f(tk, i++) != 0, sweep = f(tk, i++) != 0;
                    float nx = f(tk, i++) + ox, ny = f(tk, i++) + oy;
                    arc(s, x, y, rx, ry, large, sweep, nx, ny);
                    x = nx; y = ny; break;
                }
                default: i++;
            }
            prev = C == 'M' ? 'M' : cmd;
        }
    }

    /** SVG arc → কেন্দ্র/কোণ (ঘোরানো ছাড়া — আমাদের আইকনে লাগে না) */
    static void arc(Sink s, float x0, float y0, float rx, float ry, boolean large, boolean sweep, float x, float y) {
        if (rx == 0 || ry == 0) { s.lineTo(x, y); return; }
        rx = Math.abs(rx); ry = Math.abs(ry);
        double x1p = (x0 - x) / 2.0, y1p = (y0 - y) / 2.0;
        double lam = (x1p * x1p) / (rx * rx) + (y1p * y1p) / (ry * ry);
        if (lam > 1) { rx *= Math.sqrt(lam); ry *= Math.sqrt(lam); }
        double num = rx * rx * ry * ry - rx * rx * y1p * y1p - ry * ry * x1p * x1p;
        double den = rx * rx * y1p * y1p + ry * ry * x1p * x1p;
        double coef = (large == sweep ? -1 : 1) * Math.sqrt(Math.max(0, num / den));
        double cxp = coef * (rx * y1p / ry), cyp = -coef * (ry * x1p / rx);
        double cx = cxp + (x0 + x) / 2.0, cy = cyp + (y0 + y) / 2.0;
        double ux = (x1p - cxp) / rx, uy = (y1p - cyp) / ry, vx = (-x1p - cxp) / rx, vy = (-y1p - cyp) / ry;
        double start = Math.toDegrees(Math.atan2(uy, ux));
        double delta = Math.toDegrees(Math.atan2(ux * vy - uy * vx, ux * vx + uy * vy));
        if (!sweep && delta > 0) delta -= 360;
        else if (sweep && delta < 0) delta += 360;
        s.arc((float) cx, (float) cy, rx, ry, (float) start, (float) delta);
    }

    private static float f(List<Object> tk, int i) {
        Object o = tk.get(i);
        return o instanceof Float ? (Float) o : 0f;
    }

    private static List<Object> tokens(String d) {
        List<Object> out = new ArrayList<>();
        int i = 0, n = d.length();
        while (i < n) {
            char c = d.charAt(i);
            if (Character.isLetter(c) && c != 'e' && c != 'E') { out.add(c); i++; }
            else if (c == '-' || c == '.' || Character.isDigit(c)) {
                int j = i + 1;
                boolean dot = c == '.';
                while (j < n) {
                    char k = d.charAt(j);
                    if (Character.isDigit(k)) j++;
                    else if (k == '.' && !dot) { dot = true; j++; }
                    else break;
                }
                out.add(Float.parseFloat(d.substring(i, j)));
                i = j;
            } else i++;
        }
        return out;
    }
}
