module.exports = {
  darkMode: 'class',
  content: [
    "./templates/**/*.twig",
    "./public/assets/**/*.js",
    "./assets/**/*.css",
  ],
  theme: {
    extend: {
      colors: {
        primary: '#6366f1',    // Indigo (Mockup)
        secondary: '#8b5cf6',  // Violett (Mockup)
        accent: '#4fc3f7',
        emerald: '#10b981',
        orange: '#f97316',
      },
      animation: {
        'pulse-fast': 'pulse 1s cubic-bezier(0.4, 0, 0.6, 1) infinite',
        'blob': 'blob 7s infinite',
        'slide-up': 'slideUp 0.3s ease-out forwards',
        'fade-in': 'fadeIn 0.3s ease-in-out forwards',
      },
      keyframes: {
        blob: {
          '0%': { transform: 'translate(0px, 0px) scale(1)' },
          '33%': { transform: 'translate(30px, -50px) scale(1.1)' },
          '66%': { transform: 'translate(-20px, 20px) scale(0.9)' },
          '100%': { transform: 'translate(0px, 0px) scale(1)' },
        },
        slideUp: {
          '0%': { opacity: 0, transform: 'translate(-50%, 20px)' },
          '100%': { opacity: 1, transform: 'translate(-50%, 0)' },
        },
        fadeIn: {
          '0%': { opacity: 0, transform: 'translateY(10px)' },
          '100%': { opacity: 1, transform: 'translateY(0)' },
        },
      },
    },
  },
  plugins: [],
}
