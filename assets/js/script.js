document.addEventListener("DOMContentLoaded", () => {
  const loginForm = document.getElementById("login-form");
  const signupForm = document.getElementById("signup-form");
  const signupLink = document.getElementById("signup-link");
  const loginLink = document.getElementById("login-link");
  const tabs = Array.from(document.querySelectorAll(".auth-tab"));

  if (!loginForm || !signupForm) {
    return;
  }

  const activateForm = (targetId, updateHash = true) => {
    const showSignup = targetId === "signup-form";

    loginForm.classList.toggle("active", !showSignup);
    signupForm.classList.toggle("active", showSignup);

    tabs.forEach((tab) => {
      tab.classList.toggle("active", tab.dataset.target === targetId);
      tab.setAttribute("aria-selected", tab.dataset.target === targetId ? "true" : "false");
    });

    if (!updateHash) {
      return;
    }

    if (showSignup) {
      window.location.hash = "signup";
    } else {
      history.replaceState(null, "", window.location.pathname + window.location.search);
    }
  };

  tabs.forEach((tab) => {
    tab.addEventListener("click", () => activateForm(tab.dataset.target));
  });

  if (signupLink) {
    signupLink.addEventListener("click", () => activateForm("signup-form"));
  }

  if (loginLink) {
    loginLink.addEventListener("click", () => activateForm("login-form"));
  }

  if (window.location.hash === "#signup") {
    activateForm("signup-form", false);
  }
});
