import Alpine from 'alpinejs';
import resumableUpload from './components/resumable-upload';
Alpine.data('resumableUpload', resumableUpload);
window.Alpine = Alpine;
Alpine.start();
