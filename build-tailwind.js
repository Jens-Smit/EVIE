const tailwindcss = require('@tailwindcss/postcss');
const postcss = require('postcss');
const autoprefixer = require('autoprefixer');
const fs = require('fs');

// Read the input CSS file
const input = fs.readFileSync('./assets/styles/tailwind.css', 'utf8');

// Process the CSS with Tailwind and Autoprefixer
postcss([
  tailwindcss({
    content: [
      "./templates/**/*.html.twig",
      "./public/assets/**/*.js",
      "./public/**/*.html",
    ],
    theme: {
      extend: {
        colors: {
          primary: '#4a6fa5',
          secondary: '#166088',
          accent: '#4fc3f7',
        }
      }
    },
    plugins: [],
  }),
  autoprefixer,
])
.process(input, { from: './assets/styles/tailwind.css', to: './public/assets/styles/tailwind-compiled.css' })
.then(result => {
  // Write the compiled CSS to the output file
  fs.writeFileSync('./public/assets/styles/tailwind-compiled.css', result.css);
  console.log('Tailwind CSS successfully compiled!');
})
.catch(error => {
  console.error('Error compiling Tailwind CSS:', error);
});
