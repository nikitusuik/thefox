/**
 * Перевод названий подсказок (item_name) с английского на русский.
 * Игра "Коварный лис" — русская версия.
 */

const ITEM_TRANSLATIONS = {
  // Все подсказки из игры
  jacket: 'Куртка',
  cane: 'Трость',
  flower: 'Цветок',
  top_hat: 'Цилиндр',
  necklace: 'Ожерелье',
  glasses: 'Очки',
  pocket_watch: 'Карманные часы',
  dress: 'Платье',
  umbrella: 'Зонтик',
  magnifier: 'Лупа',
  briefcase: 'Портфель',
  scarf: 'Шарф',
  gloves: 'Перчатки',
  watch: 'Часы',
  ring: 'Кольцо',
  boots: 'Сапоги',
  bag: 'Сумка',
  wallet: 'Кошелек',
  key: 'Ключ',
  book: 'Книга',
  pen: 'Ручка',
  phone: 'Телефон',
  camera: 'Камера',
  notebook: 'Блокнот',
  map: 'Карта',
  compass: 'Компас',
  binoculars: 'Бинокль',
  rope: 'Верёвка',
  knife: 'Нож',
  flashlight: 'Фонарик',
  medal: 'Медаль',
  badge: 'Значок',
  ticket: 'Билет',
  letter: 'Письмо',
  photo: 'Фото',
  diary: 'Дневник',
  mirror: 'Зеркало',
  candle: 'Свеча',
  matches: 'Спички',
  locket: 'Медальон',
  handkerchief: 'Платок',
  pipe: 'Трубка',
  cigar: 'Сигара',
  monocle: 'Монокль',
  cufflinks: 'Запонки',
  tie_pin: 'Булавка для галстука',
  button: 'Пуговица',
  thread: 'Нить',
  needle: 'Игла',
  fabric: 'Ткань',
  ribbon: 'Лента',
  brooch: 'Брошь',
  earring: 'Серьга',
  bracelet: 'Браслет',
  
  // Альтернативные написания и дополнительные варианты
  hat: 'Шляпа',
  coat: 'Пальто',
  lantern: 'Фонарик',
  basket: 'Корзина',
  magnifying_glass: 'Лупа',
  torch: 'Факел',
  cup: 'Кружка',
  teapot: 'Чайник',
  spoon: 'Ложка',
  fork: 'Вилка',
  apple: 'Яблоко',
  bread: 'Хлеб',
  cheese: 'Сыр',
  egg: 'Яйцо',
  mushroom: 'Гриб',
  berry: 'Ягода',
  acorn: 'Жёлудь',
  leaf: 'Лист',
  feather: 'Перо',
  paw: 'Лапа',
  pawprint: 'След лапы',
  paw_print: 'След лапы',
  footprint: 'След',
  bone: 'Кость',
  stick: 'Палка',
  stone: 'Камень',
  pinecone: 'Шишка',
  pine_cone: 'Шишка',
  rose: 'Роза',
  tulip: 'Тюльпан',
  daisy: 'Ромашка',
  sunflower: 'Подсолнух',
  clover: 'Клевер',
  honey: 'Мёд',
  honeycomb: 'Соты',
  nest: 'Гнездо',
  shell: 'Ракушка',
  umbrella_alt: 'Зонтик',
  glove: 'Перчатка',
  magnifying: 'Лупа',
  magnifyingglass: 'Лупа'
}

/**
 * Переводит название предмета (подсказки) на русский
 * @param {string} itemName - оригинальное название (часто на английском)
 * @returns {string} - русский перевод
 */
export function translateItem(itemName) {
  if (!itemName || typeof itemName !== 'string') return itemName || ''
  const key = itemName.trim().toLowerCase().replace(/\s+/g, '_')
  return ITEM_TRANSLATIONS[key] || itemName
}

/**
 * Переводит массив подсказок
 * @param {string[]} hints - массив названий
 * @returns {string[]} - массив переводов
 */
export function translateHints(hints) {
  if (!Array.isArray(hints)) return []
  return hints.map(h => translateItem(h))
}
