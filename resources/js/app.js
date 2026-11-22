import Alpine from 'alpinejs';
import resumableUpload from './components/resumable-upload';
import mediaThumbnail from './components/media-thumbnail';
import videoPlayer from './components/video-player';
Alpine.data('mediaThumbnail', mediaThumbnail);
Alpine.data('videoPlayer', videoPlayer);
Alpine.data('resumableUpload', resumableUpload);
window.Alpine = Alpine;
Alpine.start();
