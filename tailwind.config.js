/**
 * Tailwind CSS configuration — shadcn/ui design tokens.
 *
 * Colours are *not* hard-coded here: every palette entry points at a CSS custom
 * property defined in `resources/css/tokens.css`, so the light/dark switch and
 * the accent presets are pure CSS with no rebuild.
 *
 * Build (local toolchain, no CDN):
 *   npm ci && npm run build          → public/assets/css/app.css
 *   npm run watch                    → during development
 *
 * @type {import('tailwindcss').Config}
 */
module.exports = {
  darkMode: ['class'],
  content: [
    './resources/views/**/*.php',
    './resources/js/**/*.js',
    './public/assets/js/**/*.js',
    './src/**/*.php',
    './config/**/*.php',
  ],
  // Classes emitted dynamically from PHP. Kept deliberately small: every entry
  // is multiplied across the whole palette, and a broad regex here blows up the
  // build. Dynamic *colour* choices go through the component classes in
  // `resources/css/components.css` (e.g. `badge-soft-success`) instead.
  safelist: [
    'sr-only', 'not-sr-only',
    { pattern: /^(col|row)-span-([1-9]|1[0-2])$/ },
    { pattern: /^grid-cols-([1-9]|1[0-2])$/ },
    { pattern: /^max-w-(sm|md|lg|xl|2xl|3xl|4xl|5xl|6xl|7xl|full|screen-lg)$/ },
    { pattern: /^w-(1\/2|1\/3|2\/3|1\/4|3\/4|full|auto)$/ },
  ],
  theme: {
    container: {
      center: true,
      padding: { DEFAULT: '1rem', sm: '1.5rem', lg: '2rem' },
      screens: { '2xl': '1500px' },
    },
    extend: {
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'Noto Sans', 'sans-serif', 'Apple Color Emoji', 'Segoe UI Emoji'],
        mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', 'Liberation Mono', 'Courier New', 'monospace'],
      },
      colors: {
        border: 'hsl(var(--border))',
        input: 'hsl(var(--input))',
        ring: 'hsl(var(--ring))',
        background: 'hsl(var(--background))',
        foreground: 'hsl(var(--foreground))',
        primary: { DEFAULT: 'hsl(var(--primary))', foreground: 'hsl(var(--primary-foreground))' },
        secondary: { DEFAULT: 'hsl(var(--secondary))', foreground: 'hsl(var(--secondary-foreground))' },
        destructive: { DEFAULT: 'hsl(var(--destructive))', foreground: 'hsl(var(--destructive-foreground))' },
        success: { DEFAULT: 'hsl(var(--success))', foreground: 'hsl(var(--success-foreground))' },
        warning: { DEFAULT: 'hsl(var(--warning))', foreground: 'hsl(var(--warning-foreground))' },
        info: { DEFAULT: 'hsl(var(--info))', foreground: 'hsl(var(--info-foreground))' },
        muted: { DEFAULT: 'hsl(var(--muted))', foreground: 'hsl(var(--muted-foreground))' },
        accent: { DEFAULT: 'hsl(var(--accent))', foreground: 'hsl(var(--accent-foreground))' },
        popover: { DEFAULT: 'hsl(var(--popover))', foreground: 'hsl(var(--popover-foreground))' },
        card: { DEFAULT: 'hsl(var(--card))', foreground: 'hsl(var(--card-foreground))' },
        sidebar: { DEFAULT: 'hsl(var(--sidebar))', foreground: 'hsl(var(--sidebar-foreground))', accent: 'hsl(var(--sidebar-accent))', border: 'hsl(var(--sidebar-border))' },
      },
      borderRadius: {
        xl: 'calc(var(--radius) + 4px)',
        lg: 'var(--radius)',
        md: 'calc(var(--radius) - 2px)',
        sm: 'calc(var(--radius) - 4px)',
      },
      boxShadow: {
        'xs': '0 1px 2px 0 hsl(var(--shadow-color) / 0.05)',
        'sm': '0 1px 2px 0 hsl(var(--shadow-color) / 0.06), 0 1px 3px 0 hsl(var(--shadow-color) / 0.08)',
        'DEFAULT': '0 1px 3px 0 hsl(var(--shadow-color) / 0.08), 0 4px 12px -2px hsl(var(--shadow-color) / 0.08)',
        'md': '0 4px 6px -1px hsl(var(--shadow-color) / 0.08), 0 10px 20px -4px hsl(var(--shadow-color) / 0.10)',
        'lg': '0 10px 15px -3px hsl(var(--shadow-color) / 0.10), 0 24px 48px -12px hsl(var(--shadow-color) / 0.18)',
        'xl': '0 20px 25px -5px hsl(var(--shadow-color) / 0.12), 0 40px 70px -16px hsl(var(--shadow-color) / 0.22)',
        'inner': 'inset 0 2px 4px 0 hsl(var(--shadow-color) / 0.06)',
        'glow': '0 0 0 1px hsl(var(--primary) / 0.15), 0 8px 30px -6px hsl(var(--primary) / 0.35)',
      },
      keyframes: {
        'accordion-down': { from: { height: '0' }, to: { height: 'var(--radix-accordion-content-height)' } },
        'accordion-up': { from: { height: 'var(--radix-accordion-content-height)' }, to: { height: '0' } },
        'fade-in': { from: { opacity: '0' }, to: { opacity: '1' } },
        'fade-in-up': { from: { opacity: '0', transform: 'translateY(6px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
        'fade-in-down': { from: { opacity: '0', transform: 'translateY(-6px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
        'scale-in': { from: { opacity: '0', transform: 'scale(.96)' }, to: { opacity: '1', transform: 'scale(1)' } },
        'slide-in-right': { from: { transform: 'translateX(100%)' }, to: { transform: 'translateX(0)' } },
        'slide-in-left': { from: { transform: 'translateX(-100%)' }, to: { transform: 'translateX(0)' } },
        'slide-up-mobile': { from: { transform: 'translateY(100%)' }, to: { transform: 'translateY(0)' } },
        'shimmer': { '100%': { transform: 'translateX(100%)' } },
        'pulse-ring': { '0%': { transform: 'scale(.85)', opacity: '.7' }, '70%': { transform: 'scale(1.6)', opacity: '0' }, '100%': { opacity: '0' } },
        'spin-slow': { to: { transform: 'rotate(360deg)' } },
        'progress-indeterminate': { '0%': { transform: 'translateX(-100%)' }, '100%': { transform: 'translateX(300%)' } },
        'toast-in': { from: { opacity: '0', transform: 'translateY(12px) scale(.97)' }, to: { opacity: '1', transform: 'translateY(0) scale(1)' } },
        'caret': { '0%,100%': { opacity: '1' }, '50%': { opacity: '0' } },
        'marquee': { from: { transform: 'translateX(0)' }, to: { transform: 'translateX(-50%)' } },
      },
      animation: {
        'accordion-down': 'accordion-down .2s ease-out',
        'accordion-up': 'accordion-up .2s ease-out',
        'fade-in': 'fade-in .18s ease-out',
        'fade-in-up': 'fade-in-up .22s cubic-bezier(.16,1,.3,1)',
        'fade-in-down': 'fade-in-down .22s cubic-bezier(.16,1,.3,1)',
        'scale-in': 'scale-in .16s cubic-bezier(.16,1,.3,1)',
        'slide-in-right': 'slide-in-right .26s cubic-bezier(.16,1,.3,1)',
        'slide-in-left': 'slide-in-left .26s cubic-bezier(.16,1,.3,1)',
        'slide-up-mobile': 'slide-up-mobile .3s cubic-bezier(.16,1,.3,1)',
        'shimmer': 'shimmer 1.8s infinite',
        'pulse-ring': 'pulse-ring 2s cubic-bezier(.24,.6,.36,1) infinite',
        'spin-slow': 'spin-slow 2.4s linear infinite',
        'progress-indeterminate': 'progress-indeterminate 1.4s ease-in-out infinite',
        'toast-in': 'toast-in .24s cubic-bezier(.16,1,.3,1)',
        'caret': 'caret 1.1s steps(1) infinite',
        'marquee': 'marquee 28s linear infinite',
      },
      spacing: {
        'sidebar': 'var(--sidebar-width)',
        'sidebar-sm': 'var(--sidebar-width-sm)',
        'header': 'var(--header-height)',
        'safe-bottom': 'env(safe-area-inset-bottom)',
        'safe-top': 'env(safe-area-inset-top)',
      },
      fontSize: {
        '2xs': ['0.6875rem', { lineHeight: '1rem' }],
      },
      zIndex: {
        '60': '60', '70': '70', '80': '80', '90': '90', '100': '100',
        'toast': '90', 'tooltip': '85', 'modal': '80', 'sheet': '75', 'dropdown': '60', 'sticky': '40',
      },
      transitionTimingFunction: {
        'spring': 'cubic-bezier(.16,1,.3,1)',
        'smooth': 'cubic-bezier(.4,0,.2,1)',
      },
      screens: { 'xs': '420px' },
      // A dense opacity ramp so tokens like `bg-primary/6` and `border-success/25`
      // work everywhere in the templates (Tailwind only emits what is used).
      opacity: Object.fromEntries(
        Array.from({ length: 100 }, (_, i) => [String(i + 1), String((i + 1) / 100)])
      ),
    },
  },
  plugins: [],
  future: { hoverOnlyWhenSupported: true },
  corePlugins: { preflight: true },
};
