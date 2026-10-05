<?php

use Andersundsehr\FrontendStudio\Transformer\Attribute\TypeTransformer;
use Andersundsehr\FrontendStudio\Control\Attribute\TypeControl;
use Andersundsehr\FrontendStudio\Control\TypeControls;
use Andersundsehr\FrontendStudio\Transformer\TypeTransformers;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

return static function (ContainerConfigurator $container, ContainerBuilder $containerBuilder): void {
    $containerBuilder->registerAttributeForAutoconfiguration(
        TypeControl::class,
        static function (ChildDefinition $definition, TypeControl $attribute, Reflector $reflector): void {
            if (!$reflector instanceof ReflectionMethod || !$reflector->isPublic() || $reflector->isStatic() || $reflector->isAbstract()) {
                throw new InvalidArgumentException('TypeControl must target a public instance method.', 1791200014);
            }

            $definition->addTag(TypeControl::TAG_NAME, ['method' => $reflector->getName(), 'id' => $attribute->id, 'type' => $attribute->type, 'priority' => $attribute->priority]);
        }
    );
    $containerBuilder->addCompilerPass(new class implements CompilerPassInterface {
        public function process(ContainerBuilder $container): void
        {
            $registry = $container->findDefinition(TypeControls::class);
            foreach ($container->findTaggedServiceIds(TypeControl::TAG_NAME) as $id => $tags) {
                foreach ($tags as $tag) {
                    $registry->addMethodCall('addControl', [new Reference($id), $tag['method'], $tag['id'], $tag['type'], $tag['priority']]);
                }
            }
        }
    });
    $containerBuilder->registerAttributeForAutoconfiguration(
        TypeTransformer::class,
        static function (ChildDefinition $definition, TypeTransformer $attribute, Reflector $reflector): void {
            if (!$reflector instanceof ReflectionMethod) {
                throw new Exception('The TypeTransformer attribute must target a method.', 1768901561);
            }

            $definition->setPublic(true);
            $returnType = $reflector->getReturnType();

            if (!$returnType) {
                throw new Exception(
                    sprintf(
                        'The method %s->%s must have a return type when using the TypeTransformer attribute.',
                        $reflector->getDeclaringClass()->getName(),
                        $reflector->getName()
                    ),
                    7990727792
                );
            }

            if ($reflector->isStatic()) {
                throw new Exception(
                    sprintf(
                        'The method %s->%s cannot be static when using the TypeTransformer attribute.',
                        $reflector->getDeclaringClass()->getName(),
                        $reflector->getName()
                    ),
                    6171768636
                );
            }

            if ($reflector->isAbstract()) {
                throw new Exception(
                    sprintf(
                        'The method %s->%s cannot be abstract when using the TypeTransformer attribute.',
                        $reflector->getDeclaringClass()->getName(),
                        $reflector->getName()
                    ),
                    4680588733
                );
            }

            if (!$reflector->getParameters()) {
                throw new Exception(
                    sprintf(
                        'The method %s->%s must have at least one parameter when using the TypeTransformer attribute.',
                        $reflector->getDeclaringClass()->getName(),
                        $reflector->getName()
                    ),
                    6793519156
                );
            }

            $definition->addTag(TypeTransformer::TAG_NAME, [
                'method' => $reflector->getName(),
                'returnType' => $returnType->__toString(),
                'priority' => $attribute->priority,
            ]);
        }
    );
    $containerBuilder->addCompilerPass(
        new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $definition = $container->findDefinition(TypeTransformers::class);

                // find all service IDs with the app.mail_transport tag
                $taggedServices = $container->findTaggedServiceIds(TypeTransformer::TAG_NAME);
                foreach ($taggedServices as $id => $tags) {
                    // add the transport service to the TransportChain service
                    foreach ($tags as $tag) {
                        $method = $tag['method'] ?? throw new Exception('The tag "' . TypeTransformer::TAG_NAME . '" must have a "method" attribute.', 7977691665,);
                        $returnType = $tag['returnType'] ?? throw new Exception('The tag "' . TypeTransformer::TAG_NAME . '" must have a "returnType" attribute.', 1865847969,);
                        $priority = $tag['priority'] ?? 0;
                        $definition->addMethodCall('addTransformer', [new Reference($id), $method, $returnType, $priority]);
                    }
                }
            }
        }
    );
};
