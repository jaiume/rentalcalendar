module.exports = {
  content: [
    './templates/**/*.twig',
    './src/**/*.php'
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          light: '#e0f2ff',
          DEFAULT: '#1d4ed8',
          dark: '#1e3a8a'
        }
      }
    }
  },
  plugins: []
};



