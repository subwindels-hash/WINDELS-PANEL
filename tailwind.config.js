/**
 * WINDELS PANEL — Tailwind config (design tokens, Session 04)
 *
 * The brand palette lives here as the source of truth for the Tailwind BUILD
 * (`npm run build:css` -> assets/css/tailwind.css, git-ignored). The same tokens
 * are mirrored as CSS custom properties in assets/css/design-system.css so the
 * PHP views render correctly even WITHOUT a Tailwind/Node build. Keep both in
 * sync.
 */

/*
 * Tailwind v3's content extractor scans raw text and can miss utility classes
 * that only appear inside PHP ternaries (<?= $x ? 'a' : 'b' ?>) or as
 * hover/focus variants across many views. Safelist those so the built CSS is
 * complete regardless of extraction quirks. Component classes live in
 * design-system.css and do not need safelisting.
 */
const stateSafelist = [
  'hover:bg-indigo-700', 'hover:bg-amber-700', 'hover:bg-slate-100',
  'hover:bg-indigo-50', 'hover:text-indigo-700', 'hover:bg-slate-900',
  'focus:border-indigo-500', 'focus:ring-2', 'focus:ring-indigo-100',
  'focus:ring-indigo-500', 'bg-indigo-50', 'text-indigo-700',
  'bg-rose-50', 'text-rose-700', 'text-amber-600', 'border-rose-100',
  'border-indigo-500', 'font-medium',
];

const windelsTokens = {
  brand: {
    50:  '#eef2ff',
    100: '#e0e7ff',
    200: '#c7d2fe',
    300: '#a5b4fc',
    400: '#818cf8',
    500: '#6366f1',
    600: '#4f46e5',
    700: '#4338ca',
    800: '#3730a3',
    900: '#312e81',
    950: '#1e1b4b',
  },
  accent: {
    50:  '#fdf4ff',
    100: '#fae8ff',
    200: '#f5d0fe',
    300: '#f0abfc',
    400: '#e879f9',
    500: '#d946ef',
    600: '#c026d3',
    700: '#a21caf',
  },
  success: { 50:'#ecfdf5', 500:'#10b981', 600:'#059669', 700:'#047857' },
  warning: { 50:'#fffbeb', 500:'#f59e0b', 600:'#d97706', 700:'#b45309' },
  danger:  { 50:'#fef2f2', 500:'#ef4444', 600:'#dc2626', 700:'#b91c1c' },
  info:    { 50:'#eff6ff', 500:'#3b82f6', 600:'#2563eb', 700:'#1d4ed8' },
};

module.exports = {
  darkMode: 'class',
  content: [
    './application/views/**/*.php',
    './assets/js/**/*.js',
  ],
  safelist: stateSafelist,
  theme: {
    extend: {
      colors: {
        // ADDITIVE brand tokens — Tailwind's default palette (indigo, rose,
        // cyan, gray, ...) stays intact, so existing utility classes in the
        // homepages/auth views keep compiling.
        brand: windelsTokens.brand,
        accent: windelsTokens.accent,
        success: windelsTokens.success,
        warning: windelsTokens.warning,
        danger: windelsTokens.danger,
        info: windelsTokens.info,
        // Themeable neutrals. `slate` and `surface` are driven by CSS
        // variables (--ws-slate-*, --ws-surface) defined in
        // assets/css/design-system.css, so a single `.dark` class on <html>
        // re-themes every slate/surface utility across the app without editing
        // individual views. `white`/`black` stay literal (used as text on
        // coloured buttons/badges) — surfaces are `bg-surface` instead.
        slate: {
          50:  'rgb(var(--ws-slate-50) / <alpha-value>)',
          100: 'rgb(var(--ws-slate-100) / <alpha-value>)',
          200: 'rgb(var(--ws-slate-200) / <alpha-value>)',
          300: 'rgb(var(--ws-slate-300) / <alpha-value>)',
          400: 'rgb(var(--ws-slate-400) / <alpha-value>)',
          500: 'rgb(var(--ws-slate-500) / <alpha-value>)',
          600: 'rgb(var(--ws-slate-600) / <alpha-value>)',
          700: 'rgb(var(--ws-slate-700) / <alpha-value>)',
          800: 'rgb(var(--ws-slate-800) / <alpha-value>)',
          900: 'rgb(var(--ws-slate-900) / <alpha-value>)',
          950: 'rgb(var(--ws-slate-950) / <alpha-value>)',
        },
        surface: 'rgb(var(--ws-surface) / <alpha-value>)',
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Helvetica', 'Arial', 'sans-serif'],
        display: ['Fraunces', 'Georgia', 'Cambria', 'Times New Roman', 'serif'],
        mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'monospace'],
      },
      borderRadius: {
        '2xl': '1rem',
        '3xl': '1.5rem',
      },
      boxShadow: {
        card: '0 1px 2px rgba(16,24,40,.05), 0 1px 3px rgba(16,24,40,.08)',
        'card-hover': '0 10px 30px -10px rgba(79,70,229,.35)',
        glow: '0 0 0 4px rgba(99,102,241,.15)',
      },
      maxWidth: { content: '1200px' },
      zIndex: { dropdown: '40', sticky: '50', modal: '100' },
      keyframes: {
        'fade-in': { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
        'slide-up': { '0%': { opacity: '0', transform: 'translateY(8px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
      },
      animation: {
        'fade-in': 'fade-in .25s ease-out',
        'slide-up': 'slide-up .3s ease-out',
      },
    },
  },
  plugins: [],
};

module.exports.windelsTokens = windelsTokens;
