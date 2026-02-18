import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  base: '/foxthegame/',

  plugins: [vue()],

  server: {
    proxy: {
      '/API': {
        target: 'https://se.ifmo.ru',
        changeOrigin: true,
        secure: true,
        rewrite: (path) => path.replace(/^\/API/, '/~s368719/kovar/API'),
      },
    },
  },
})
