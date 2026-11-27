// Teste de navegador do módulo de reorganização (chamado por reorganizacao-fluxo-teste.php).
// Uso: node navegador.js <participante|layout> '<json de configuração>'
// "participante": entra pela Central de Equipes, espera o lote (o PHP cria o lote ao receber a linha
// {"espera":...}), preenche o Grupo 1, simula perda de conexão e confere o salvamento automático.
// "layout": abre as páginas em 1366×768 e 1280×720 e confere que não há rolagem lateral.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const readline = require('readline');

const [etapa, cfgJson] = process.argv.slice(2);
const cfg = JSON.parse(cfgJson || '{}');
const saida = (o) => process.stdout.write(JSON.stringify(o) + '\n');
const esperarLinha = () => new Promise((ok) => {
  const rl = readline.createInterface({ input: process.stdin });
  rl.once('line', (l) => { rl.close(); ok(l); });
});

async function participante(browser) {
  const r = {};
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 768 } });
  const page = await ctx.newPage();
  const erros = [];
  page.on('pageerror', (e) => erros.push(String(e)));
  await page.goto(cfg.base + 'modulos/reorganizacao/equipe/');
  await page.fill('input[name="nome"]', 'Participante Navegador');
  await Promise.all([page.waitForLoadState('load'), page.click('button[type="submit"]')]);
  r.instrucoes = await page.locator('input[name="acao"][value="instrucoes"]').count() === 1;
  await Promise.all([page.waitForNavigation(), page.click('form:has(input[value="instrucoes"]) button[type="submit"]')]);
  await Promise.all([page.waitForNavigation(), page.click('form:has(input[name="equipe"][value="grupo1"]) button[type="submit"]')]);
  r.espera = (await page.textContent('h1')).includes('Aguardando a atribuição de um lote pelo administrador.');
  saida({ espera: r.espera });
  await esperarLinha(); // o PHP criou o lote

  try {
    await page.waitForSelector('[data-reorg-novo]', { timeout: 40000 });
    r.loteApareceu = true;
  } catch (e) { r.loteApareceu = false; }
  if (cfg.capturas) { await page.screenshot({ path: path.join(cfg.capturas, 'g1-inicio-1366.png') }); }
  await page.click('[data-reorg-novo]');
  await page.waitForSelector('input[name="titulo"]', { timeout: 15000 });
  const ind = '[data-reorg-indicador-form]';
  const estado = () => page.getAttribute(ind, 'data-estado');
  const esperarEstado = async (valor, ms) => {
    try { await page.waitForFunction(([s, v]) => document.querySelector(s)?.getAttribute('data-estado') === v, [ind, valor], { timeout: ms }); return true; } catch (e) { return false; }
  };

  await page.fill('input[name="titulo"]', 'Livro do Navegador');
  await page.waitForTimeout(300);
  r.salvo = await esperarEstado('salvo', 15000) && (await page.textContent(ind)).includes('Todas as alterações salvas');

  await ctx.setOffline(true);
  await page.fill('input[name="autores"]', 'OFFLINE, Teste Fictício');
  r.offlineIndicador = await esperarEstado('offline', 15000);
  r.textoOffline = await page.textContent(ind);
  r.rascunhoLocal = await page.evaluate(() => Object.keys(localStorage).some((k) => k.startsWith('reorg-rascunho-v2-') && (localStorage.getItem(k) || '').includes('OFFLINE')));
  if (cfg.capturas) { await page.screenshot({ path: path.join(cfg.capturas, 'g1-sem-conexao-1366.png') }); }
  await ctx.setOffline(false);
  r.sincronizou = await esperarEstado('salvo', 25000);

  const max = parseInt(await page.getAttribute('[data-reorg-registro]', 'data-max'), 10);
  await page.fill('input[name="quantidade"]', '99');
  await page.dispatchEvent('input[name="quantidade"]', 'change');
  await page.waitForTimeout(200);
  r.max = max;
  r.valorQuantidade = await page.inputValue('input[name="quantidade"]');
  r.quantidadeLimitada = max === cfg.maxEsperado && parseInt(r.valorQuantidade, 10) <= max;
  await page.fill('input[name="quantidade"]', '1');
  await esperarEstado('salvo', 15000);
  if (cfg.capturas) { await page.screenshot({ path: path.join(cfg.capturas, 'g1-formulario-1366.png'), fullPage: true }); }
  r.url = page.url();
  r.errosJs = erros;
  await ctx.storageState({ path: cfg.estado });
  await ctx.close();
  saida(r);
}

async function layout(browser) {
  const telas = [{ nome: '1366x768', width: 1366, height: 768 }, { nome: '1280x720', width: 1280, height: 720 }];
  const paginas = [];
  for (const t of telas) {
    const adm = await browser.newContext({ viewport: { width: t.width, height: t.height } });
    const p = await adm.newPage();
    await p.goto(cfg.base + 'admin/login.php');
    await p.fill('input[name="email"]', 'adm@teste.br');
    await p.fill('input[name="senha"]', cfg.senha);
    await Promise.all([p.waitForNavigation(), p.click('button[type="submit"]')]);
    const listas = [...cfg.paginasAdmin.map((u) => [p, u])];
    const part = await browser.newContext({ viewport: { width: t.width, height: t.height }, storageState: cfg.estado });
    const pp = await part.newPage();
    for (const u of cfg.paginasEquipe) { listas.push([pp, u]); }
    for (const [pg, u] of listas) {
      await pg.goto(cfg.base + u, { waitUntil: 'load' });
      await pg.waitForTimeout(150);
      const m = await pg.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: window.innerWidth, titulo: document.title }));
      const ok = m.sw <= m.iw + 1;
      paginas.push({ tela: t.nome, pagina: u, ok, ...m });
      if (cfg.capturas) {
        const nome = u.replace(/^modulos\/reorganizacao\//, '').replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'inicio';
        await pg.screenshot({ path: path.join(cfg.capturas, `${t.nome}-${nome}.png`) });
      }
    }
    await adm.close();
    await part.close();
  }
  saida({ paginas });
}

(async () => {
  if (cfg.capturas) { fs.mkdirSync(cfg.capturas, { recursive: true }); }
  const browser = await chromium.launch();
  try {
    if (etapa === 'participante') { await participante(browser); } else { await layout(browser); }
  } catch (e) {
    saida({ erro: String(e && e.stack || e) });
  } finally {
    await browser.close();
  }
})();
