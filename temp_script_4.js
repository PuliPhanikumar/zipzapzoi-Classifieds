
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              primary: "#019863",
              secondary: "#4D96A9",
              "background-light": "#f0f4f8",
              "background-dark": "#0f172a",
              "card-dark": "#1e293b"
            },
            fontFamily: { display: "Spline Sans" }
          }
        }
      };
      
      if (localStorage.getItem('zzz_theme') === 'dark' || (!('zzz_theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
      }
    