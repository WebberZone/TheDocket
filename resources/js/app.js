import Alpine from 'alpinejs';
import reader from './reader';

window.Alpine = Alpine;

Alpine.data('reader', reader);

Alpine.start();
