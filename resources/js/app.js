import './bootstrap';
import { createApp } from 'vue';
import App from './App.vue';
import router from './router';

const app = createApp(App);

// Global error handler
app.config.errorHandler = (err, instance, info) => {
  console.error('Global error:', err);
  console.error('Component:', instance);
  console.error('Error info:', info);

  // Optionally show user-friendly error message
  // You can integrate with a toast notification system here
  if (import.meta.env.PROD) {
    // In production, show a generic error message
    alert('Произошла ошибка приложения. Пожалуйста, перезагрузите страницу.');
  }
};

app.use(router);
app.mount('#app');