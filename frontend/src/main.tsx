import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import "./index.css";
import App from "./App";
import { ThemeProvider } from "./app/providers/ThemeProvider";
import { AuthProvider } from "./app/providers/AuthContext";
import { ToastProvider } from "./components/ui/Toast";
import { ConfirmProvider } from "./components/ui/ConfirmDialog";

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <ThemeProvider>
      <AuthProvider>
        <ToastProvider>
          <ConfirmProvider>
            <BrowserRouter>
              <App />
            </BrowserRouter>
          </ConfirmProvider>
        </ToastProvider>
      </AuthProvider>
    </ThemeProvider>
  </StrictMode>
);
