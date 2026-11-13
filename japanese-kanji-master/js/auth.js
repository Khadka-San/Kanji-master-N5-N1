
const form = document.getElementById("form");
const notice = document.getElementById("notice");
const submit = document.getElementById("submit");
const heading = document.getElementById("heading");
const subtitle = document.getElementById("subtitle");
const nameWrap = document.getElementById("nameWrap");
const nameInput = document.getElementById("name");
const teacherWrap = document.getElementById("teacherWrap");
const teacherCode = document.getElementById("teacherCode");
const switchText = document.getElementById("switchText");
const switchButton = document.getElementById("switch");

let role = "student";
let mode = "login";
let busy = false;

function showMessage(message, type = "error") {
  notice.textContent = message;
  notice.className = `notice show ${type}`;
}

function updateForm() {
  const registering = mode === "register";

  heading.textContent = registering ? "Create your account" : "Welcome back";
  subtitle.textContent = registering
    ? "Enter your details to get started."
    : "Enter your details to sign in to your account.";

  nameWrap.hidden = !registering;
  nameInput.required = registering;
  teacherWrap.hidden = role !== "teacher";
  teacherCode.required = role === "teacher";

  submit.textContent = registering ? "Create account" : "Sign in";
  switchText.textContent = registering
    ? "Already have an account?"
    : "New here?";
  switchButton.textContent = registering
    ? "Sign in"
    : "Create an account";

  document.querySelectorAll(".tab").forEach(button => {
    const selected = button.dataset.role === role;
    button.classList.toggle("active", selected);
    button.setAttribute("aria-pressed", String(selected));
  });

  notice.className = "notice";
  notice.textContent = "";
}

function showRegistrationSuccess(email, password) {
  const overlay = document.createElement("div");
  overlay.className = "success-overlay";
  overlay.setAttribute("role", "dialog");
  overlay.setAttribute("aria-modal", "true");

  overlay.innerHTML = `
    <section class="success-modal">
      <div class="success-check">✓</div>
      <p class="success-eyebrow">ACCOUNT CREATED</p>
      <h2>Congratulations!</h2>
      <p class="success-copy">
        Your account has been created successfully.
      </p>
      <p class="success-question">
        Would you like to continue to the app?
      </p>
      <div class="success-actions">
        <button type="button" class="success-yes">Yes, let's go</button>
        <button type="button" class="success-no">No, stay here</button>
      </div>
      <p class="success-foot">
        You can sign in anytime with your new account.
      </p>
    </section>
  `;

  if (!document.getElementById("successModalStyles")) {
    const style = document.createElement("style");
    style.id = "successModalStyles";
    style.textContent = `
      .success-overlay {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: grid;
        place-items: center;
        padding: 20px;
        background: rgba(4,8,20,.82);
        backdrop-filter: blur(10px);
      }
      .success-modal {
        width: min(100%,420px);
        padding: 32px 28px;
        text-align: center;
        color: #f5f7ff;
        background: #121a30;
        border: 1px solid rgba(255,255,255,.13);
        border-radius: 24px;
        box-shadow: 0 28px 100px rgba(0,0,0,.5);
      }
      .success-check {
        display: grid;
        place-items: center;
        width: 62px;
        height: 62px;
        margin: 0 auto 18px;
        border-radius: 50%;
        color: #071a19;
        font-size: 30px;
        font-weight: 900;
        background: linear-gradient(135deg,#66e7ce,#b5aaff);
      }
      .success-eyebrow {
        color: #66e7ce;
        font-size: 10px;
        font-weight: 850;
        letter-spacing: .2em;
      }
      .success-modal h2 { font-size: 30px; }
      .success-copy { color: #aebbd7; line-height: 1.7; }
      .success-question { margin: 24px 0 14px; font-weight: 750; }
      .success-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
      }
      .success-actions button {
        min-height: 46px;
        padding: 10px;
        border-radius: 11px;
        font-weight: 800;
        cursor: pointer;
      }
      .success-yes {
        border: 0;
        color: #0b1020;
        background: linear-gradient(100deg,#9d91ff,#65e8d0);
      }
      .success-no {
        border: 1px solid rgba(255,255,255,.14);
        color: #e5eaf8;
        background: rgba(255,255,255,.04);
      }
      .success-foot { color: #7583a3; font-size: 10px; }
      @media(max-width:420px) {
        .success-modal { padding: 28px 20px; }
        .success-actions { grid-template-columns: 1fr; }
      }
    `;
    document.head.appendChild(style);
  }

  document.body.appendChild(overlay);

  overlay.querySelector(".success-no").addEventListener("click", () => {
    overlay.remove();
    mode = "login";
    role = "student";
    updateForm();
    document.getElementById("email").value = email;
    document.getElementById("password").value = "";
    showMessage(
      "Your account is ready. Sign in whenever you're ready.",
      "success"
    );
  });

  overlay.querySelector(".success-yes").addEventListener("click", async event => {
    const button = event.currentTarget;
    button.disabled = true;
    button.textContent = "Opening app...";

    try {
      const response = await fetch("api/login.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: JSON.stringify({
          email,
          password,
          role: "student"
        })
      });

      const result = await response.json();

      if (!response.ok || !result.success) {
        overlay.remove();
        mode = "login";
        role = "student";
        updateForm();
        document.getElementById("email").value = email;
        showMessage(result.message || "Please sign in to continue.");
        return;
      }

      window.location.href = result.redirect || "dashboard.php";
    } catch (error) {
      button.disabled = false;
      button.textContent = "Try again";
      overlay.querySelector(".success-copy").textContent =
        "Your account was created, but the app could not open. Please try again.";
    }
  });
}

document.querySelectorAll(".tab").forEach(button => {
  button.addEventListener("click", () => {
    role = button.dataset.role;
    updateForm();
  });
});

switchButton.addEventListener("click", () => {
  mode = mode === "login" ? "register" : "login";
  updateForm();
});

form.addEventListener("submit", async event => {
  event.preventDefault();
  if (busy) return;

  const email = document.getElementById("email").value.trim();
  const password = document.getElementById("password").value;
  const name = nameInput.value.trim();
  const code = teacherCode.value.trim();

  if (!email || !password) {
    showMessage("Please enter your email and password.");
    return;
  }

  if (mode === "register" && !name) {
    showMessage("Please enter your full name.");
    return;
  }

  if (mode === "register" && password.length < 8) {
    showMessage("Please use a password with at least 8 characters.");
    return;
  }

  if (role === "teacher" && !code) {
    showMessage("Please enter the teacher access code.");
    return;
  }

  const registering = mode === "register";
  const endpoint = registering ? "api/register.php" : "api/login.php";
  const payload = { email, password, role };

  if (registering) payload.name = name;
  if (role === "teacher") payload.teacherCode = code;

  busy = true;
  submit.disabled = true;
  submit.textContent = registering
    ? "Creating account..."
    : "Signing in...";

  try {
    const response = await fetch(endpoint, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify(payload)
    });

    const result = await response.json();

    if (!response.ok || !result.success) {
      throw new Error(result.message || "Something went wrong.");
    }

    if (registering) {
      showRegistrationSuccess(email, password);
    } else {
      showMessage(result.message || "Signed in successfully.", "success");
      if (result.redirect) {
        window.location.href = result.redirect;
      }
    }
  } catch (error) {
    showMessage(
      error.message || "Cannot connect to the server. Check your PHP API."
    );
  } finally {
    busy = false;
    submit.disabled = false;
    submit.textContent =
      mode === "register" ? "Create account" : "Sign in";
  }
});

updateForm();
