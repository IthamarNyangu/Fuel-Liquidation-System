const signupLink = document.getElementById("signup-link");
const loginLink = document.getElementById("login-link");
const loginForm = document.getElementById("login-form");
const signupForm = document.getElementById("signup-form");

signupLink.addEventListener("click", (e) => {
  e.preventDefault();
  loginForm.classList.add("slide-out");
  loginForm.classList.remove("active");
  signupForm.classList.add("active");
});

loginLink.addEventListener("click", (e) => {
  e.preventDefault();
  signupForm.classList.remove("active");
  loginForm.classList.remove("slide-out");
  loginForm.classList.add("active");
});