import type { Config } from "tailwindcss";

const config: Config = {
  content: [
    "./src/pages/**/*.{js,ts,jsx,tsx,mdx}",
    "./src/components/**/*.{js,ts,jsx,tsx,mdx}",
    "./src/app/**/*.{js,ts,jsx,tsx,mdx}",
  ],
  theme: {
    extend: {
      colors: {
        pitch: {
          DEFAULT: "#0B5D3B",
          dark: "#073F28",
          deep: "#042618",
        },
        electric: {
          DEFAULT: "#7CFF4D",
          hover: "#65E636",
          dark: "#55C728",
        },
        graphite: {
          DEFAULT: "#0E1714",
          surface: "#14201C",
          card: "#192823",
          border: "#243B33",
        },
      },
    },
  },
  plugins: [],
};

export default config;
