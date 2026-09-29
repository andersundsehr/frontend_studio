import type { VariantMetadata } from './variantMetadata.d.ts';

export async function fetchVariants(baseUrl: string): Promise<Array<VariantMetadata>> {
  const baseUrlObject = new URL(baseUrl);
  baseUrlObject.pathname = '/__frontendStudio/variants';
  const url = `${baseUrlObject}`;
  let response: Response;
  try {
    response = await fetch(url);
  } catch (e) {
    console.error(`Failed to fetch ${url}`);
    throw e;
  }
  if (!response.ok) {
    console.error({ response });
    throw new Error(`Failed to fetch ${url} : ${response.statusText}`);
  }
  const data = (await response.json()) as { variants: Array<VariantMetadata> };
  // validate the data.variants and only return if type matches

  if (!data.variants || !Array.isArray(data.variants)) {
    throw new Error(`Invalid response from ${url}: ${JSON.stringify(data)}`);
  }
  for (const variant of data.variants) {
    if (
      typeof variant.url !== 'string' ||
      typeof variant.componentName !== 'string' ||
      typeof variant.phpNamespace !== 'string' ||
      typeof variant.variantName !== 'string' ||
      typeof variant.fileName !== 'string'
    ) {
      throw new Error(`Invalid variant metadata: ${JSON.stringify(variant)} required: { url: string, componentName: string, phpNamespace: string, variantName: string, fileName: string }`);
    }
  }
  return data.variants;
}
